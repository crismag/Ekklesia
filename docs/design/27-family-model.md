# Family detection, and why it cannot work as it stands

## What the data says

Measured against the live roster.

| | |
|---|---|
| Families | 157 |
| People | 306 |
| People with a family | 252 |
| People with no family | 54 |
| **Families with exactly one member** | **103 of 157 (66%)** |
| Families with 3 or more members | 28 |
| **People with any family role recorded** | **14 of 306 (5%)** |
| **Families whose members all share one surname** | **156 of 157** |
| Surnames used by more than one family | 27, covering 62 families |
| Addresses shared by more than one family | 17, covering 43 families |

Two of those numbers settle the question.

**Two thirds of families have one member.** A table where most rows describe a
single person is not recording families. It is recording addresses, one per
person, and calling the result a family.

**Ninety-five percent of people have no family role.** Husband, Wife, Child and
Other Relative are recorded for fourteen people in total. Whatever kinship the
church knows about, it is not in here.

And **156 of 157 families contain exactly one surname** — not because families
share surnames, but because a person who does not share the surname ends up in
a family of their own. The data cannot show problem 4; it can only produce it.

## The one cause

`person_per.per_fam_ID` is a single number. A person belongs to one family, or
to none.

That single fact produces four of the five problems reported:

- **A wife who kept her name** cannot be in her husband's family and keep a
  different surname without the family becoming mixed — so she is filed as her
  own family. (Problem 4, and the 156-of-157 figure above.)
- **A married child** cannot be both a child in their parents' family and a
  spouse in their own. One of those has to be discarded. (Problem 2.)
- **A family across two addresses** cannot exist, because the address lives on
  the family row. (Problem 5.)
- **Same surname, different families** is then unavoidable, because the surname
  is doing the work the missing relationships should be doing. (Problem 1.)

Problem 3 is different, and worth separating: **two families at one address is
not an error.** Adult children, lodgers, a couple hosting a parent — all real.
The mistake is treating a shared address as evidence of one family. Seventeen
addresses here have more than one family, and most of those are probably
correct.

## What is being conflated

Three different things are all called "family":

1. **Household** — who lives at this address. Changes when someone moves.
2. **Kinship** — who is related to whom, and how. Does not change when someone
   moves, and does not care about surnames.
3. **Surname** — a string. Evidence of nothing.

Detection cannot be improved while these are one field, because the answers
genuinely conflict. Better heuristics on a model that cannot express the answer
will keep producing confident wrong groupings — which is worse than none.

## What exists already

`RelatedFamiliesService` stores admin-confirmed links between two **families**
in `config/related-families.json`. It correctly identifies the constraint —
its own comment says "a person belongs to only one family" — and works around
it without touching ChurchCRM. It is currently empty.

Family-to-family is the wrong grain. "The Cruz family is related to the Santos
family" cannot say *who* is related to *whom*, so it cannot draw a tree, and it
inherits the surname problem it was meant to solve.

## Proposed model

Keep the three things apart.

**Household stays where it is.** `family_fam` plus its address is a good
household record and is what ChurchCRM syncs. Two households at one address
stay two households. Nothing here changes.

**Kinship becomes a graph.** A portal-side table of person-to-person links:

```
person_relationship
  person_id, related_person_id, relation, created_by, created_at
  relation ∈ spouse | parent | child | sibling | guardian | other
```

Many-to-many, so a person can be a child in one link and a spouse in another —
which is exactly what a multi-generational household needs. Surnames and
addresses are irrelevant to it. Portal-side, following the pattern already used
by `portal_users`, `ministry_leaders` and `person_campus_affiliation`: additive,
reversible, no ChurchCRM schema change.

A "family" for display is then **derived** — the connected component containing
a person, optionally limited by degree — rather than stored. That is the family
tree, and it answers all five problems because none of them are about surnames
or addresses once the relationships are recorded.

**Surname stops being evidence.** Duplicate detection is about whether two
records are the same *person*, which is a question about names, birth dates and
contact details — not about families.

## The cost, stated plainly

Relationships have to be entered. Nothing derives them reliably: that is the
whole finding. Address and surname can *suggest* candidates for a person to
confirm, and a confirmed link is worth more than a hundred guessed ones, but
somebody has to confirm them.

The 28 families with three or more members are where the value is, and where
the entry cost is smallest.
