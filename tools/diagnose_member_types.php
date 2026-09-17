<?php
/**
 * Read-only probe: discover where the church's member types
 * (G&A / Radical / TrailBlazers) live in ChurchCRM. The user said they're
 * in "person_custom" — this script lists every custom_master row and which
 * person_custom columns hold non-empty values, so we can build the right
 * filter query for the roster editor.
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require_once $path;
});
use App\Core\Config\EnvLoader;
use App\Core\Database\ChurchCrmConnection;
EnvLoader::loadOnce(__DIR__ . '/../.env');
$pdo = ChurchCrmConnection::get();
if ($pdo === null) { echo "FAIL: ChurchCRM PDO not available\n"; exit(1); }

echo "person_custom_master rows (custom field definitions):\n";
echo str_repeat('-', 78) . "\n";
$rows = $pdo->query('SELECT custom_Order, custom_Field, custom_Name, type_ID FROM person_custom_master ORDER BY custom_Order')->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as $r) {
    printf("  #%-3d %-30s  type=%-3d  field=%s\n", $r['custom_Order'], $r['custom_Name'], $r['type_ID'], $r['custom_Field']);
}

echo "\nperson_custom columns:\n";
echo str_repeat('-', 78) . "\n";
$cols = $pdo->query("SHOW COLUMNS FROM person_custom")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($cols as $c) {
    printf("  %-30s %s\n", $c['Field'], $c['Type']);
}

echo "\nNon-empty value counts per custom field:\n";
echo str_repeat('-', 78) . "\n";
foreach ($cols as $c) {
    $name = $c['Field'];
    if ($name === 'per_ID') continue;
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM person_custom WHERE `$name` IS NOT NULL AND `$name` <> ''"
    )->fetchColumn();
    if ($count > 0) {
        $sample = $pdo->query(
            "SELECT DISTINCT `$name` FROM person_custom WHERE `$name` IS NOT NULL AND `$name` <> '' LIMIT 5"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        printf("  %-30s rows=%-5d  e.g. [%s]\n", $name, $count, implode(' | ', array_slice($sample, 0, 5)));
    }
}

// Each custom field referencing a list (type_ID >= 12) maps to entries in
// list_lst keyed by lst_ID = type_ID. Resolve the option labels for c1.
echo "\nList options for the Member Type custom field (list_lst):\n";
echo str_repeat('-', 78) . "\n";
$type = (int) $pdo->query("SELECT type_ID FROM person_custom_master WHERE custom_Field = 'c1' LIMIT 1")->fetchColumn();
$opts = $pdo->prepare(
    "SELECT lst_OptionID, lst_OptionName, lst_OptionSequence
       FROM list_lst WHERE lst_ID = :tid ORDER BY lst_OptionSequence"
);
$opts->bindValue(':tid', $type, PDO::PARAM_INT);
$opts->execute();
foreach ($opts->fetchAll(PDO::FETCH_ASSOC) as $row) {
    printf("  optionID=%-3d  %-25s  seq=%d\n", $row['lst_OptionID'], $row['lst_OptionName'], $row['lst_OptionSequence']);
}

// Look at every list_lst row so we can spot the right list for the custom
// field (type_ID=12 collided with another list above).
echo "\nlist_lst rows mentioning member-type words:\n";
echo str_repeat('-', 78) . "\n";
$probe = $pdo->query(
    "SELECT lst_ID, lst_OptionID, lst_OptionName, lst_OptionSequence
       FROM list_lst
      WHERE lst_OptionName REGEXP 'g&a|gift.?and.?arrow|radical|trail.?blazer|member.?type'
   ORDER BY lst_ID, lst_OptionSequence"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($probe as $row) {
    printf("  lst_ID=%-4d  optionID=%-3d  %-30s  seq=%d\n",
        $row['lst_ID'], $row['lst_OptionID'], $row['lst_OptionName'], $row['lst_OptionSequence']);
}

// Show people known by name and their c1 values so we can ground the mapping.
echo "\nSample people and their c1 values:\n";
echo str_repeat('-', 78) . "\n";
$known = $pdo->query(
    "SELECT p.per_FirstName, p.per_LastName, pc.c1
       FROM person_per p
  LEFT JOIN person_custom pc ON pc.per_ID = p.per_ID
      WHERE p.per_FirstName IN ('Anne','Cristopher','Erma','RJ','Justin','Trisha','Ramon','Kim','Gertrude')
   ORDER BY p.per_LastName"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($known as $row) {
    printf("  %-15s %-20s c1=%s\n", $row['per_FirstName'], $row['per_LastName'], $row['c1'] ?? 'NULL');
}

echo "\nDistribution of c1 (Member Type) across people:\n";
echo str_repeat('-', 78) . "\n";
$dist = $pdo->query(
    "SELECT pc.c1 AS v, l.lst_OptionName AS label, COUNT(*) AS n
       FROM person_custom pc
  LEFT JOIN list_lst l ON l.lst_ID = $type AND l.lst_OptionID = pc.c1
      WHERE pc.c1 IS NOT NULL
   GROUP BY pc.c1, l.lst_OptionName
   ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($dist as $row) {
    printf("  c1=%-3d  %-25s  count=%d\n", $row['v'], $row['label'] ?? '(no list_lst label)', $row['n']);
}
