# Accounts and access

Ekklesia accounts are separate from member records: an account signs a person
in, and its roles decide what they can do. Administrators create accounts and
link each one to the person it belongs to.

## Signing in

| Method | Detail |
| --- | --- |
| Password | Sign in with your email or phone number and password |
| Google | Offered when the installation has Google sign-in configured; signs in the existing account with the same verified email. Uses identity scopes only — no access to Gmail, Drive or Calendar. |
| Emailed link | Offered when the installation can send email; a single-use link, valid for 15 minutes, sent only to an address on one active account |

Google and emailed links sign in **existing** accounts only. They never create
an account, a member record or a role, and an unavailable method is hidden.
Repeated failed attempts are slowed down. A session lasts twelve hours.

New accounts are created by an administrator with a temporary password, and
the person is asked to choose their own the first time they sign in.
Administrator-set passwords are at least 12 characters. Changing your own
password signs out your other sessions. There is no self-service "forgot
password": an administrator sets a new temporary password.

## Roles and scope

| Role | Purpose |
| --- | --- |
| **Member** | The standard account: your own serving schedule, availability, profile and the calendar |
| **Scheduler** | Builds serving schedules and manages activities, including cancelling and deleting dates |
| **Leader** | Leads a ministry: schedules, activities, serving roles and positions, and leader-only activities |
| **Admin** | Everything; with no scope, the **portal-wide administrator** with the control panel |

| Permission | Member | Scheduler | Leader | Admin |
| --- | :---: | :---: | :---: | :---: |
| Own assignments and availability; ministry dashboard | ✓ | ✓ | ✓ | ✓ |
| Manage schedules; view ministry schedules and people's availability | | ✓ | ✓ | ✓ |
| Create and edit activities | | ✓ | ✓ | ✓ |
| Manage serving roles and positions | | | ✓ | ✓ |
| Cancel and delete activity dates | | ✓ | | ✓ |
| See leader-audience activities | | | ✓ | ✓ |

**Scope.** A role can be limited to a campus, a ministry or both, and an
account can hold several roles; its permissions and scopes are combined. To
manage a ministry's schedule, a scheduler or leader needs a role scoped to that
ministry. Only an **Admin role with no scope** opens Administration — member
records, households, visitors, accounts and settings — and an admin role with
a scope works with the permissions above, inside its scope, without the
control panel.

All of this is checked on the server for every request, not just by hiding
buttons.

## Managing accounts

Portal-wide administrators manage accounts in **Administration → Accounts**
(`/admin/users`):

- create an account and link it to a person (one person per account);
- add or remove roles, each with its campus or ministry scope;
- set a temporary password and require a change at next sign-in;
- activate, deactivate or delete an account.

Ekklesia protects itself from lock-outs: an administrator cannot deactivate or
delete their own account, and the last active portal-wide administrator — or
their administrator role — cannot be removed.

**Account activity** (`/admin/history`) is the full audit log of account and
record changes, filterable and paged, with each account's last sign-in.
