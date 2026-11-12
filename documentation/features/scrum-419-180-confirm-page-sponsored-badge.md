# One confirm page for every link, and the "Sponsored" badge (SCRUM-419, SCRUM-180)

These ship in one PR, with one commit per ticket. The accounts below all come from the normal seed
(`documentation/seeded-data.md`), and their password is `password`.

## 1. Organisation join links use the shared confirm page (SCRUM-419)

### What was built
Opening an organisation join link now shows the same confirm page (`Links/Confirm`) as guardianship,
therapy-counsellor, discussion and group-counsellor links. The separate join page is gone.

What you see hasn't changed:
- the organisation's logo, or its first letter;
- its name and description;
- "Your request goes to the organization's admins…";
- a **request to join** button.

After you answer:
- you see "Request sent to …" or "Your request couldn't be sent.";
- the button doesn't come back;
- a **Go to My organizations** link appears.

A link that can't be used shows "This join link can't be used", the reason, and **Go to My organizations**. Opening
the link still changes nothing; only the button sends the request.

### Try it
1. In tinker, make a join link for Org Demo Wellness Collective (organisation 1), created by `org_demo_admin`:
   ```php
   App\Models\Link::forceCreate(['uuid' => (string) Str::uuid(), 'type' => 'ORGANIZATION_SELF_APPLY',
       'state' => 'ACTIVE', 'addedby_type' => App\Models\User::class,
       'addedby_id' => App\Models\User::where('username', 'org_demo_admin')->value('id'),
       'for_type' => App\Models\Organization::class, 'for_id' => 1]);
   ```
2. Open `/links/{uuid}` as `org_demo_admin`. You should see "This join link can't be used — You cannot use a link
   you created."
3. Open it as `payment_demo_client`. You should see the organisation and a **request to join** button.
4. Press **request to join**. You should see "Request sent to Org Demo Wellness Collective." and a link to My
   organizations.

Step 4 adds a pending application. To undo it, withdraw it from My organizations or delete it in tinker.

## 2. The "Sponsored" badge (SCRUM-180)

### What was built
When an organisation pays for someone's therapy, they see a small **Sponsored** badge on:
- its card (Home and their therapy lists);
- the therapy page's **details** tab.

Hovering over the badge shows "Paid by <organisation>", and screen readers read the same. Only the therapy's own
client ever sees it. Its counsellor, guardians, admins and anyone browsing public therapies never get it, because the
organisation may be the person's employer or school.

An organisation "pays" when either of these is true:
- **A charge to the organisation's card:**
  - it was for the therapy, or for one of its sessions;
  - it succeeded and wasn't refunded;
  - if more than one organisation paid, the latest one is named.
- **The organisation's retainer covers them:** this applies to paid therapies with that organisation's counsellors.

For groups, only a retainer counts. A group's "my organisation pays" still charges the member's own card today, and
SCRUM-420 is the ticket to change that.

**The therapy page's "covered under …'s plan" line has also changed:**
- It names the organisation only to the client, or to anyone on a public, non-anonymous therapy.
- The counsellor of a private or anonymous therapy now reads "covered under an organization's plan", without the name.

### Test data
| Account | What to look at |
|---|---|
| `org_demo_member` | Home: "Org Retainer Demo Therapy (Per Therapy)" and "(Per Session)" show **Sponsored** (covered by a retainer). |
| `org_demo_payperuse_member` | `/therapies/14` "Org Pay-Per-Use Demo Therapy" → **details** tab shows **Sponsored**, from a charge to the organisation's card. |
| `org_demo_counsellor` | `/therapies/12`: no badge. The **payment details** tab reads "covered under an organization's plan", with no name. |

If your local database was seeded before this change, the pay-per-use charge's reference starts `org_demo_charge_`.
Re-seed, or rename its prefix to `org_charge_`, to see the badge on `/therapies/14`.

### Try it
1. Log in as `org_demo_member` and open Home. Both retainer demo therapy cards show **Sponsored**. Hover over one:
   "Paid by Org Demo Wellness Collective".
2. Log in as `org_demo_payperuse_member` and open `/therapies/14` → **details**. The badge is under "Payment Type".
3. Log in as `org_demo_counsellor` and open `/therapies/12`:
   - there's no badge;
   - the **payment details** tab says it's covered under "an organization's plan".
