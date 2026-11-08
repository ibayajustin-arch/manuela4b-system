# Manuela 4B MIS: Diagram Notes

These notes explain how to read and export the four diagrams, the modeling decisions behind them, and what was validated. The evidence behind each diagram is in `analysis-report.md`.

| File | Diagram |
|---|---|
| `resident-flowchart.mmd` | Resident Flowchart |
| `admin-flowchart.mmd` | Admin Flowchart |
| `dfd-level-0.mmd` | Level 0 Data Flow Diagram (context diagram) |
| `dfd-level-1.mmd` | Level 1 Data Flow Diagram |

## Rendering and export

All four are Mermaid source files and were rendered with Mermaid CLI 11.4.2.

- **Online:** paste the file contents into [mermaid.live](https://mermaid.live), then export as PNG or SVG.
- **Command line:** `mmdc -i dfd-level-1.mmd -o dfd-level-1.svg -b white`
- **For print:** export as SVG or a high-resolution PNG. The two flowcharts and Level 1 are large and may need A3 paper or a landscape fold-out page to stay readable.

Keep these files out of the InfinityFree upload. They are documentation only.

## Notation

### Flowcharts

| Symbol | Meaning |
|---|---|
| Rounded terminator | Start, End, or end of a predefined process |
| Rectangle | Process step |
| Parallelogram | User input |
| Diamond | Decision, with labeled branches |
| Double-sided rectangle | Predefined process (Payment OTP Verification) |
| Circle A | Return to the dashboard |
| Circle B | Return to login |

Connectors A and B replace long return lines. The highlighted box is the dashboard, which is the navigation center of each flowchart.

### Data flow diagrams

| Symbol | Meaning |
|---|---|
| Square-cornered box | External entity |
| Rounded box with a number | Process |
| Box with side bars and a D number | Data store |
| Arrow with text | Data flow |
| Double-headed arrow | The process reads from and writes to that store |
| `*` | Repeated symbol, drawn more than once to avoid crossing lines |

**Notation limits in Mermaid**

- Mermaid has no open-ended rectangle, the standard Gane-Sarson data store symbol. The side-barred box is the closest available shape, and its meaning is stated here and in the file header. If your panel requires the exact symbol, export to SVG and replace the shapes in an editor such as draw.io.
- Mermaid's edge labels overlapped when many flows ran between the same two nodes. External flow labels are therefore written as text boxes sitting on the arrow line. The arrow direction still shows the flow direction.
- Mermaid chooses the layout automatically, so some flows take curved or longer routes.

## Data stores

| No. | Store | Definition source |
|---|---|---|
| D1 | `users` | Dump |
| D2 | `homeowners` | Dump and migrations |
| D3 | `payments` | Dump |
| D4 | `reservations` | Dump; payment fields inferred from code |
| D5 | `reservation_payments` | Inferred from code |
| D6 | `announcements` | Dump |
| D7 | `resident_feedback` | Code |
| D8 | `resident_feedback_photos` | Code |
| D9 | `activity_log` | Dump; some fields inferred from code |
| D10 | `otp_codes` | Inferred from code |
| D11 | `login_attempts` | Inferred from code |
| D12 | `user_identities` | Inferred from code |
| D13 | Verification ID files | `storage/verification_ids/` folder, not a database table |

**Left out on purpose**

- `password_resets`: the active recovery flow uses `otp_codes`. This table is only cleared during a reset.
- `dues_reminder_runs`, `dues_reminder_log`: read by admin notifications, but nothing in this code writes to them.
- `feedback_photos`: created only by a helper file that no page loads.

The live database was not inspected, so inferred stores should be checked against a schema-only export before final submission.

## Modeling decisions

| Topic | Decision | Reason |
|---|---|---|
| Resident Dashboard | Labeled "Resident Dashboard (Profile Account page)" | The landing page is `resident/profile.php`. No page is named Dashboard. |
| Application rejection | No rejection branch. "Approve application? No" means no system action, and the application stays pending. | The code has no reject action. Password login still shows a not-active message for other statuses. |
| Dashboards in the DFD | Grouped in process 7.0, as internal functions | They retrieve and display data owned by other processes. |
| Announcements for residents | Shown through 7.0, not 6.0 | Residents read them on the home page and in notifications. |
| 3.0 and 4.0 | No direct flow between them; they exchange data through D4 and D5 | Both work on the same records. The booking page starts checkout, and the payment success page updates status and calls auto-approval. |
| Payment | PayMongo is an external entity. The Resident-to-PayMongo payment is not drawn in the DFDs. | Flows between two external entities are outside the system boundary. It appears as a step in the Resident Flowchart. |
| Email recipients | Email Service is the external entity | The system's boundary ends when it hands the message to the mail service. |
| Admin payment verification | Not shown | The system confirms online payments with PayMongo. The admin monitors records and can record manual payments. |
| Facilities | Tennis Court is not shown by name | The config includes it alongside Court and Clubhouse. The diagrams refer to "facility" in general. |

## Simplifications

- **Booking OTP:** the flowchart shows OTP verification before the booking form. The code also enforces it when the form is submitted.
- **Webhook:** the Resident Flowchart shows the browser return path. The dues webhook is shown in the DFD as "webhook events", and it can record the same payment through the same receipt number.
- **Homeowner create and edit:** the "Details valid?" decision is a summary. These two pages were checked by search and not read line by line.
- **Lookups:** read-only joins for names and streets are shown as "Account records" or "Resident records".
- **Google sign-in:** the Resident Flowchart shows no account-status check because the code has none.

## Validation results

**Performed on the final files:**

| Check | Method | Result |
|---|---|---|
| All four files render | Mermaid CLI | Passed; no syntax errors |
| Level 0 and Level 1 balance | Script compared entity, direction, and label | Passed; 33 of 33 external flows match, none on one level only |
| Each process has inputs and outputs | Script | Passed for 1.0 to 7.0 |
| Each data store is both written and read | Script | Passed for D1 to D13 |
| Each flowchart decision has at least 2 labeled branches | Script | Passed; 36 decisions (Resident), 24 (Admin) |
| No dead ends or unreachable steps | Script | Passed; connectors and terminators excluded by design |
| No secrets in the output files | Pattern search for key formats and password assignments | None found |
| Visual review | Rendered images reviewed | No overlapping text. Long, curved, and some crossing lines remain in both DFDs. In Level 1, process 6.0 is drawn between 2.0 and 3.0 because Mermaid sets the order. |

**Reviewed against the analysis report:**

- Resident and Admin flows are separate, and each dashboard is the center of its flowchart.
- Payment states match the code: success, cancelled, and not confirmed. No failed state is shown because no code writes one.
- Both reservation approval paths are shown: system auto-approval and admin decision.
- Dashboards are internal functions, not external entities.
- PayMongo is included because the integration is real.

**Not validated:**

- The application was not run, so paths are confirmed from code only.
- A real PayMongo payment and webhook delivery were not tested.
- Data stores were not compared with the live database.
- Exported diagrams were not test-printed on paper.

## Code issues reflected in the diagrams

These were found during analysis. No application code was changed.

1. No reject action exists for pending applications.
2. Google sign-in does not check whether the resident account is still active.
3. The webhook does not handle reservation payments, so a reservation can stay unpaid if the resident pays but never returns to the success page.
4. The webhook reads only the test-mode signature and would reject live-mode events.
5. Manual payments allow Cash, Bank Transfer, and Other, while the project rule says GCash and Maya only.
6. Tennis Court remains in the facility config, while the project rule says Court and Clubhouse only.
