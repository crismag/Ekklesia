<?php
/**
 * Carry ChurchCRM user rights into Ekklesia logins.
 *
 *   php database/migrate/access_from_legacy_users.php [members_db] [legacy_db]
 *
 * In the Church Portal, a ChurchCRM user (user_usr) was given portal roles when
 * their login was created or synced: usr_Admin → admin (portal-wide),
 * usr_ManageGroups without admin → scheduler (their campus). Ekklesia reads
 * roles only from account_roles, so this writes those rights down once:
 *
 *  - a person who already has a login gets the role on their oldest login (the
 *    one login uses for that person);
 *  - a person with no login gets the login the portal's first sign-in would have
 *    created: their email (or the phone-keyed address), the default password
 *    ChristLike#<first initial><last initial>#2026!, must change it on first
 *    sign-in, a member role, and the right above.
 *
 * Reads the legacy database and writes the member database on the same server
 * (arguments, else MEMBERS_DB / LEGACY_DB; arguments survive sudo), through LEGACY_DSN / LEGACY_USER /
 * LEGACY_PASSWORD. Safe to run again: nothing is added twice.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/app/Core/Security/PasswordHasher.php';
require $root . '/app/Services/PersonIdentityResolver.php';

$membersDb = $argv[1] ?? (getenv('MEMBERS_DB') ?: 'christlikeness_members');
$legacyDb = $argv[2] ?? (getenv('LEGACY_DB') ?: 'u471078694_churchcrm_v0');
foreach ([$legacyDb, $membersDb] as $name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        fwrite(STDERR, "Invalid database name: $name\n");
        exit(1);
    }
}

$db = new PDO(
    getenv('LEGACY_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4',
    getenv('LEGACY_USER') ?: 'root',
    getenv('LEGACY_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$hasher = new App\Core\Security\PasswordHasher();

$users = $db->query(
    "SELECT u.usr_per_ID AS person_id, COALESCE(u.usr_Admin, 0) AS is_admin, COALESCE(u.usr_ManageGroups, 0) AS manages_groups,
            p.first_name, p.last_name, p.email, p.mobile_phone, p.home_phone, p.campus_id
       FROM `$legacyDb`.user_usr u
       JOIN `$membersDb`.people p ON p.id = u.usr_per_ID
      WHERE COALESCE(u.usr_Admin, 0) = 1 OR COALESCE(u.usr_ManageGroups, 0) = 1
      ORDER BY u.usr_per_ID"
)->fetchAll();

$findAccount = $db->prepare("SELECT id FROM `$membersDb`.user_accounts WHERE person_id = :p ORDER BY id LIMIT 1");
$emailTaken = $db->prepare("SELECT id FROM `$membersDb`.user_accounts WHERE email = :e");
$hasRole = $db->prepare(
    "SELECT 1 FROM `$membersDb`.account_roles r JOIN `$membersDb`.user_accounts a ON a.id = r.account_id
      WHERE a.person_id = :p AND r.role = :role AND r.ministry_id IS NULL AND (r.campus_id <=> :campus OR :role2 = 'admin')"
);
$createAccount = $db->prepare(
    "INSERT INTO `$membersDb`.user_accounts (person_id, email, password_hash, display_name, is_active, must_change_password)
     VALUES (:p, :e, :h, :n, 1, 1)"
);
$addRole = $db->prepare("INSERT INTO `$membersDb`.account_roles (account_id, role, campus_id, ministry_id) VALUES (:a, :role, :campus, NULL)");

foreach ($users as $u) {
    $personId = (int) $u['person_id'];
    $name = trim($u['first_name'] . ' ' . $u['last_name']);
    $role = (int) $u['is_admin'] === 1 ? 'admin' : 'scheduler';
    // An admin is portal-wide; a scheduler works within their campus.
    $campus = $role === 'admin' ? null : ($u['campus_id'] !== null ? (int) $u['campus_id'] : null);

    $hasRole->execute([':p' => $personId, ':role' => $role, ':role2' => $role, ':campus' => $campus]);
    if ($hasRole->fetchColumn() !== false) {
        echo "  {$name} (#{$personId}): already {$role}\n";
        continue;
    }

    $findAccount->execute([':p' => $personId]);
    $accountId = $findAccount->fetchColumn();
    if ($accountId === false) {
        $phone = App\Services\PersonIdentityResolver::normalizePhone((string) ($u['mobile_phone'] ?: $u['home_phone']));
        $email = $u['email'] !== null && trim((string) $u['email']) !== '' ? strtolower(trim((string) $u['email'])) : null;
        if ($email !== null) {
            $emailTaken->execute([':e' => $email]);
            if ($emailTaken->fetchColumn() !== false) {
                $email = null; // shared with a family member's login
            }
        }
        $login = $email ?? ($phone !== '' ? 'phone+' . $phone . '@portal.local' : null);
        if ($login === null) {
            echo "  {$name} (#{$personId}): SKIPPED, no email or phone to sign in with; add one, then run again\n";
            continue;
        }
        $default = App\Services\PersonIdentityResolver::DEFAULT_PASSWORD_PREFIX
            . strtoupper(substr(trim((string) $u['first_name']), 0, 1) ?: '?')
            . strtoupper(substr(trim((string) $u['last_name']), 0, 1) ?: '?')
            . App\Services\PersonIdentityResolver::DEFAULT_PASSWORD_SUFFIX;
        $createAccount->execute([':p' => $personId, ':e' => $login, ':h' => $hasher->hash($default), ':n' => $name]);
        $accountId = (int) $db->lastInsertId();
        $addRole->execute([':a' => $accountId, ':role' => 'member', ':campus' => $u['campus_id']]);
        echo "  {$name} (#{$personId}): created login {$login} (default password, must change)\n";
    }

    $addRole->execute([':a' => (int) $accountId, ':role' => $role, ':campus' => $campus]);
    echo "  {$name} (#{$personId}): {$role} on login #{$accountId}\n";
}
