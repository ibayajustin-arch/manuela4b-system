# Manuela 4B MIS: Phase 1 Analysis Report

Prepared on 2026-11-05 from the working tree on branch `main`. This report is the reference for the four diagrams in Phase 2.

## 1. Method and evidence limits

| Label | Meaning |
|---|---|
| **Verified** | Seen in the PHP or SQL source that was read |
| **Inferred** | Follows from how the code uses a table or field, but no definition was found |
| **Unverified** | Could not be confirmed from what was inspected |

Limits that affect confidence:

- **The application was not run.** All findings come from reading code. No page, payment, or email was tested.
- **The live database was not inspected.** A read-only query for table structure was denied by the tool's permission check. The schema comes from the structure statements in `manuela4b_mis.sql` at `HEAD`, the migration files, and the code. No row data was read. That dump has since been deleted from the working tree.
- **The committed dump is older than the code.** See section 7.
- **Some files were read selectively.** Page logic was read closely. Long HTML sections and some CRUD pages (`homeowner_create.php`, `homeowner_edit.php`) were checked by search only.
- **No secrets were copied.** `config/db.php` and `includes/mail.php` were not opened. Key values in `config/paymongo.php` were masked when it was read. `config/oauth.php` was searched for provider names only.

## 2. Project structure

**Actual stack:** PHP without a framework, MySQL through mysqli, Bootstrap 5, vanilla JavaScript, Chart.js, and PHPMailer. It runs on XAMPP locally and on InfinityFree when deployed.

| Path | Purpose |
|---|---|
| `login.php`, `register.php`, `logout.php`, `index.php` | Shared login, registration, logout, and role-based redirect |
| `admin/login.php` | Admin login entry; loads `login.php` with `$login_role = 'admin'` |
| `forgot_password.php`, `verify_otp.php`, `reset_password.php` | Password recovery by emailed code |
| `oauth_start.php`, `oauth_callback.php`, `oauth_disconnect.php` | Google sign-in and account linking |
| `settings.php`, `terms.php`, `receipt.php` | Shared settings, terms page, and receipts |
| `resident/` | Resident pages, PayMongo checkout, payment return pages, webhook |
| `Admin/` | Admin pages |
| `Admin/api/` | Analytics JSON endpoints |
| `includes/` | Shared helpers: authentication, OTP, availability, notifications, analytics |
| `config/` | Database, OAuth, PayMongo, and facility settings |
| `storage/verification_ids/` | Private storage for ID images |
| `database/`, `sample_data.sql` | Migrations and sample data |

Not part of the MIS: `OmniCopilot/` (a separate VS Code extension project), `backup_20261018/`, `_css_backup_*/`, `.tmp/`.

## 3. Resident workflow

### 3.1 Registration and verification (Verified)

1. The resident opens `register.php` and enters name, username, email, contact number, street, house number, and password, and agrees to the terms.
2. The resident submits front and back ID images.
3. The system validates the CSRF token, required fields, listed street, field lengths, email and contact format, password length (at least 8), matching passwords, terms agreement, image type (JPG, PNG, WebP), and size (5 MB each, 8 MB combined).
4. The system checks that the username is unique. Failures return an error to the form.
5. In one transaction, the system creates `users` (role `homeowner`), saves the ID images, and creates `homeowners` with status `pending`. If this fails, it rolls back and removes the images.
6. The resident sees "Application submitted" and waits for an admin. No email verification step exists at registration.

### 3.2 Login (Verified)

1. The resident enters a username, email, or phone number with a password, or uses Google sign-in.
2. The system checks the CSRF token and login limits (5 failures per user in 5 minutes, 15 per IP address in 15 minutes).
3. The system verifies the password, then checks that the account matches the login portal.
4. The system checks `homeowners.status`:
   - `pending`: blocked, with an "awaiting admin verification" message
   - any other non-active status: blocked, with a "not active" message
   - `active`: session starts and the resident goes to `resident/profile.php`

Google sign-in works only for an account already linked from Settings. It does not create accounts. `oauth_callback.php` and `complete_login()` **do not check `homeowners.status`**, so a linked resident who was later deactivated or archived can still sign in with Google (**Verified** from code; not run).

### 3.3 Password recovery (Verified)

`forgot_password.php` → 6-digit code by email → `verify_otp.php` → `reset_password.php` → password updated and codes deleted. Requests and wrong attempts are limited.

### 3.4 Resident modules

**Navigation:** Profile Account, Payments, Facility Reservation, Suggestions & Concerns, Settings, Logout, and a notification bell.

**Online Payments (`resident/payments.php`)**
- Shows outstanding balance or "All Paid Up", and payment history with reference number, amount, status, and receipt.
- The resident can pay, view a receipt, and archive or restore a receipt.
- See section 8 for the payment flow.

**Facility Reservation (`resident/reservations.php`)**
- Availability calendar and a booking form.
- Facilities in config: Court, Tennis Court, Clubhouse. Open 6:00 AM to 10:00 PM, whole hours only.
- Rates: ₱200 per hour from 6:00 AM to 4:00 PM, ₱300 per hour from 4:00 PM to 10:00 PM.
- Payment option: full or 50% downpayment.
- The list shows status and offers Pay Now for unpaid future bookings, plus a receipt once paid.
- The page's only POST action is `book`. No resident cancellation of a reservation was found.

**Suggestions & Concerns (`resident/feedback.php`)**
- Categories: suggestion, concern, incident.
- Incident reports add category, date and time, location, urgency, photos, and a reference number.
- The resident can view status and admin replies, and archive or restore their own messages.

**Announcements**
- There is no separate resident announcements page. Announcements appear on `resident/profile.php` and in notifications, and can be opened in a modal.

**Settings (`settings.php`)**
- Change password and connect or disconnect Google. Profile photo upload is handled by `resident/upload_photo.php`.

**Logout:** the session is cleared and the resident returns to `login.php`.

## 4. Resident Dashboard

> **Important finding:** no page or menu item is named "Resident Dashboard". After login, the resident lands on `resident/profile.php`, which is titled "Profile" and labeled "Profile Account" in the sidebar. This page acts as the resident's home page. The diagrams need an agreed label (see section 13).

| Question | Finding |
|---|---|
| Information shown on login | Profile photo, name, account status, role, block and lot, email and contact details, community announcements, emergency and household details |
| Statistics or summaries | None on this page. Balance and payment history are on `resident/payments.php` |
| Notifications | Bell in the shared layout: announcements, reservation status, activity on the resident's own account, recorded payments, admin replies |
| Interactive elements | Edit profile, edit emergency and household details, upload photo, open an announcement, open Settings |
| Navigation from this page | Sidebar and mobile bottom bar to Payments, Reservations, Suggestions & Concerns, Settings, Logout |
| Records retrieved | `users`, `homeowners`, `announcements`; `reservations`, `payments`, `activity_log`, and `resident_feedback` for notifications |

## 5. Admin workflow

**Login (Verified):** `admin/login.php` uses the same login logic and requires an admin account. A resident account is told to use the resident login. Admin pages redirect anyone without the admin role.

| Module | Files | Implemented operations |
|---|---|---|
| Resident verification | `pending_homeowners.php`, `homeowner_id_document.php` | Review pending applications and ID images. **Approve** sets status `active` and the dues start date, and logs the action. **No reject action exists.** |
| Homeowner management | `homeowners.php`, `homeowner_create/edit/view/action/activity.php` | Search, filter, create, edit, view account summary and activity, reset password, deactivate or reactivate, archive or restore |
| Dues | `settings.php?section=dues` | Adjust `monthly_due` per homeowner. `manage_dues.php` only redirects here. |
| Payments | `payments.php`, `record_payment.php`, `send_reminder.php`, `view_receipt.php` | Search and filter records, export CSV, view receipts, archive or restore, record manual payments, email payment reminders |
| Reservations | `reservations.php` | View and filter by street. Approve or reject pending reservations, with an overlap check on approval. Archive or restore. |
| Resident feedback | `feedback.php` | View messages and incident details, set status (`new`, `in_review`, `resolved`), reply, archive or restore |
| Announcements | `announcements.php` | Post, archive, restore |
| Visual Data Analytics | `analytics.php`, `api/analytics_finance.php`, `Assets/js/admin-analytics.js` | Six views with date and street filters, plus CSV export |
| Reports | `report.php` | Report endpoint using date ranges. No link from the UI was found (**Unverified** whether it is reachable from a page). |
| Settings | `settings.php` | Change password, manage dues |

## 6. Admin Dashboard

Implemented in `Admin/Dashboard.php`, with the chart in `Assets/js/admin-dashboard-analytics.js`.

| Component | Data source | Action |
|---|---|---|
| Greeting and View Analytics button | Session, `users` | Opens Analytics |
| KPI: Total Homeowners | `homeowners` | Opens Homeowners |
| KPI: Revenue This Month, with change from last month | `payments` | Opens Payments |
| KPI: Pending Dues | `homeowners`, `payments` | Opens Analytics |
| Revenue Overview line chart | `api/analytics_finance.php` | Display only |
| Quick Actions | None | Add Homeowner, Manage Dues, Post Announcement |
| Needs Follow-up | `homeowners`, `payments` | Details modal → homeowner page, and link to the late-payment analytics view |
| Upcoming Reservations | `reservations`, `users` | Opens Reservations |
| Collection Progress ring | `homeowners`, `payments` | Display only |
| Recent Transactions | `payments`, `homeowners` | Opens Payments |

**Navigation:** Dashboard, Visual Data Analytics, Reservations, Homeowners, Payments, Announcements, Resident Feedback, Settings, Logout, notification bell. The Pending Residents page is reached from Homeowners or from a notification.

**Analytics views:** Overview, Collections and Payments, Who Pays Late, Reservations, Resident Feedback, Forecast.

## 7. Database

### 7.1 Tables

| Table | Purpose | Key fields | Definition source |
|---|---|---|---|
| `users` | Login accounts | PK `id`; UQ `username`; `password`, `full_name`, `role` | Dump |
| `homeowners` | Resident records and verification | PK `id`; FK `user_id`; UQ `lot_number`; `block`, `lot`, `email`, `contact`, `monthly_due`, `status`, ID image paths, `archived_at` | Dump and migrations |
| `payments` | Dues payments | PK `id`; FK `homeowner_id`, `collector_id`; UQ `receipt_no`; `amount_paid`, `payment_date`, `period_covered`, `payment_method`, `notes`, `archived_at` | Dump |
| `reservations` | Facility bookings | PK `id`; `user_id`, `facility`, `res_date`, `start_time`, `end_time`, `status` | Dump; other fields inferred |
| `reservation_payments` | Reservation payment transactions | `id`, `reservation_id`, `amount`, `payment_status`, `reference_number`, `checkout_session_id`, `payment_method`, `payment_id`, `paid_at` | **Inferred** from code |
| `announcements` | Community announcements | PK `id`; FK `created_by`; `title`, `message`, `archived_at` | Dump |
| `resident_feedback` | Suggestions, concerns, incidents | PK `id`; `user_id`, `category`, `subject`, `message`, `status`, `admin_reply`, incident fields | Code (`includes/feedback.php`, `includes/incident_helpers.php`) |
| `resident_feedback_photos` | Incident photos | PK `id`; `feedback_id`, `file_name` | Code |
| `activity_log` | Audit trail | PK `id`; `user_id`, `homeowner_id`, `admin_name`, `action`, `details` | Dump; `homeowner_id` and `admin_name` inferred |
| `otp_codes` | Reset and payment codes | `user_id`, `purpose`, `code_hash`, `expires_at`, `used_at`, `attempts` | **Inferred** |
| `login_attempts` | Throttling | `identifier`, `ip` | **Inferred** |
| `user_identities` | Linked Google accounts | `user_id`, `provider`, `provider_user_id`, `email`, `display_name` | **Inferred** |
| `password_resets` | Older token-based reset | `user_id`, `token_hash`, `expires_at` | **Inferred**; appears to be legacy |
| `dues_reminder_runs`, `dues_reminder_log` | Automated reminder logs | `period`, `status` | **Inferred**; read by notifications, but no writer exists in this code |

### 7.2 Relationships

- **Foreign keys in the dump:** `homeowners.user_id` → `users.id`; `payments.homeowner_id` → `homeowners.id`; `payments.collector_id` → `users.id`; `announcements.created_by` → `users.id`.
- **Logical links from code (foreign key not verified):** `reservations.user_id` → `users.id`; `reservation_payments.reservation_id` → `reservations.id`; `resident_feedback.user_id` → `users.id`; `resident_feedback_photos.feedback_id` → `resident_feedback.id`; `otp_codes`, `user_identities`, and `password_resets` `.user_id` → `users.id`.

### 7.3 Schema discrepancies

- The dump lacks fields the code uses: `reservations.total_amount`, `payment_amount`, `payment_status`, `payment_option`, `archived_at`; `users.profile_photo`; `homeowners.dues_start_date`.
- The dump lacks these tables: `reservation_payments`, `otp_codes`, `login_attempts`, `user_identities`, `password_resets`, `resident_feedback`, and others listed above.
- `homeowner_action.php` and `homeowner_view.php` refer to `sql/homeowner_profile_upgrade.sql`, which is not in the project. The fields it would add are `ownership_type`, `emergency_contact_name`, `emergency_contact_number`, `household_members`.
- `resident/incident-helpers.php` creates `feedback_photos`, but no file loads this helper. The active table is `resident_feedback_photos`, so `feedback_photos` is excluded from the diagrams.

### 7.4 Dashboard data sources

| Dashboard | Tables |
|---|---|
| Resident home page | `users`, `homeowners`, `announcements`; notifications add `reservations`, `payments`, `activity_log`, `resident_feedback` |
| Admin Dashboard | `homeowners`, `payments`, `reservations`, `users`; notifications add `activity_log`, `announcements`, `resident_feedback`, and the reminder logs |

## 8. Online Payment Management

**Gateway (Verified):** PayMongo Checkout Sessions, limited to `gcash` and `paymaya`. The code and config comment indicate **test mode**.

### 8.1 Monthly dues

1. The resident opens Payments and sees the balance, computed from `homeowners` and `payments`.
2. The resident selects Pay. `create_checkout.php` requires a verified payment session.
3. If not verified, `verify_payment.php` emails a 6-digit code. The code is valid for 10 minutes, and a successful check allows 10 minutes to start the payment. It is used up once checkout starts.
4. The system validates the period (not before dues start, not in the future) and rejects a month already fully paid.
5. The system creates a PayMongo checkout session for the remaining amount and redirects the resident.
6. The resident pays through GCash or Maya on PayMongo.
7. **Success:** `payment_success.php` retrieves the session from PayMongo and checks that the payment intent is `succeeded`. It then inserts into `payments` with receipt `PM-<last 8 characters of the session ID>`.
8. **Webhook:** `webhook.php` validates the signature, handles `checkout_session.payment.paid`, and inserts into `payments` with the same receipt number. The unique `receipt_no` prevents a duplicate.
9. **Cancel:** the resident returns to `payments.php`; nothing is recorded.
10. **Not confirmed:** the resident sees "Payment could not be verified."

### 8.2 Reservation payments

1. The resident must pass the same emailed code check before booking.
2. The system validates the booking, takes a database lock per venue, and checks for conflicts.
3. In one transaction, it creates `reservations` (`pending`, `unpaid`) and `reservation_payments` (`pending`, with a `RES-...` reference), then creates the PayMongo session. On an error it rolls back.
4. **Success:** `payment_success.php?reservation_id=` confirms with PayMongo, marks both records `paid`, and calls `reservation_auto_approve()`.
   - No overlap with an approved booking: status becomes `approved` ("Confirmed").
   - Overlap found: status stays `pending` ("Being reviewed") for the admin.
5. **Cancel:** `payment_cancel.php` keeps the reservation, and the resident can use Pay Now through `pay_reservation.php`.
6. **Not confirmed:** the resident is told to check again later.

### 8.3 Payment states

| Record | States found | Notes |
|---|---|---|
| `payments` (dues) | No status field | A row means paid; `archived_at` hides it. Dues have no stored pending or failed state. |
| `reservations.payment_status` | `unpaid`, `paid` | `pending` is also checked in the display code |
| `reservation_payments.payment_status` | `pending`, `paid` | No code writes a failed state |
| `reservations.status` | `pending`, `approved`, `rejected` | A rejected reservation is shown as "Cancelled" to the resident |

### 8.4 Admin side

- Online payments are confirmed by the system against PayMongo. **The admin has no verify-payment step.**
- The admin can monitor, filter, export, archive, and view receipts.
- The admin can record manual payments. `record_payment.php` accepts Cash, GCash (admin-assisted), Maya (admin-assisted), Bank Transfer, and Other.

## 9. External entities

| Entity | Evidence | Exchange |
|---|---|---|
| Resident | Verified | Primary user |
| Admin | Verified | Primary user |
| PayMongo Payment Gateway | `resident/create_checkout.php`, `pay_reservation.php`, `reservations.php`, `payment_success.php`, `webhook.php` | Checkout requests, session and payment status, webhook events |
| Email Service (SMTP) | PHPMailer in `includes/auth_helpers.php`, `includes/otp.php`, `Admin/send_reminder.php` | One-time codes, payment reminders |
| Google OAuth | `oauth_*.php` | Sign-in request, verified Google profile |

## 10. Data inputs and outputs

| Entity | Inputs to the system | Outputs from the system |
|---|---|---|
| Resident | Registration details, ID images, login credentials, profile updates, booking details, payment requests and codes, feedback and incident reports | Application status, login result, profile and household information, announcements, notifications, balance, payment history, receipts, availability, reservation status, feedback status and replies |
| Admin | Login credentials, verification decisions, homeowner records, dues changes, manual payments, reminder requests, reservation decisions, announcements, feedback status and replies, analytics filters | Pending applications and ID images, homeowner records and activity, KPIs, follow-up lists, payment records, CSV exports, receipts, reservation lists, feedback, charts and forecasts, notifications |
| PayMongo | Session and payment status, signed webhook events | Checkout session requests, status queries |
| Email Service | None read by the system except send status | Code emails, reminder emails |
| Google OAuth | Verified Google profile | Authorization and token requests |

## 11. Main system processes (proposed for Level 1)

| No. | Process | Data stores |
|---|---|---|
| 1.0 | Account Registration and Authentication | `users`, `homeowners`, `otp_codes`, `login_attempts`, `user_identities`, verification ID files |
| 2.0 | Resident Verification and Homeowner Management | `homeowners`, `users`, `activity_log`, verification ID files; reads `payments`, `reservations` |
| 3.0 | Facility Reservation Management | `reservations`, `reservation_payments`, `activity_log`; reads `users`, `homeowners` |
| 4.0 | Online Payment and Dues Management | `payments`, `reservation_payments`, `reservations`, `homeowners`, `otp_codes`, `activity_log`; reads `users` |
| 5.0 | Suggestions, Concerns, and Incident Management | `resident_feedback`, `resident_feedback_photos`; reads `users`, `homeowners` |
| 6.0 | Announcements Management | `announcements`, `activity_log`; reads `users` |
| 7.0 | Dashboards, Notifications, and Visual Data Analytics | Reads the stores above; writes nothing |

The originally suggested separate "Resident Dashboard" process is folded into 7.0 because the dashboards mainly retrieve and present data owned by other processes.

## 12. Implemented versus incomplete

| Feature | Status | Note |
|---|---|---|
| Registration with ID submission | Implemented | |
| Admin approval | Implemented | |
| Admin rejection of an application | **Not implemented** | No action on the pending page; `set_status` buttons do not show for pending accounts |
| Email notice on approval | **Not found** | The approval code sends no email |
| Resident home page | Implemented as `profile.php` | Not named or built as a dashboard |
| Admin Dashboard and analytics | Implemented | |
| Dues and reservation online payment | Implemented | PayMongo test mode |
| Webhook for dues | Implemented | Reads the test-mode signature only |
| Webhook for reservations | **Not implemented** | Relies on the resident's browser returning to the success page |
| Failed or expired payment status | **Not implemented** | |
| Downpayment balance payment | **Not found** | After a downpayment is paid, the status is `paid`. No page for the remainder was found. |
| Resident cancellation of a reservation | **Not found** | |
| Automated dues reminders | **Incomplete** | Logs are read, but no writer or scheduled job is in this code. Manual reminders work. |
| Treasurer and collector roles | **Not implemented** | Exist only in the dump's `role` values. No code references them. |

## 13. Discrepancies and items to decide

| No. | Item | Project description or rule | Actual code | Proposed diagram treatment |
|---|---|---|---|---|
| 1 | Resident Dashboard | A resident dashboard exists | Home page is `resident/profile.php` | Label it "Resident Dashboard (Profile Account page)" |
| 2 | Rejection | Approve or reject | Approve only | Show approval, and show unapproved applications staying `pending`. No rejection branch. |
| 3 | Backend | PHP and Node.js | PHP only. The only `package.json` belongs to `OmniCopilot/`. | Document PHP only |
| 4 | Facilities | Court and Clubhouse only | Config also has Tennis Court, which shares the Court venue | Follow the code, or show "Court" and "Clubhouse" and add a note |
| 5 | Payment methods | GCash and Maya only | True online. Manual recording also allows Cash, Bank Transfer, and Other. | Show online as GCash/Maya and manual as a separate admin process |
| 6 | Auto-approval | Reservations are auto-approved | True after payment with no overlap. Admin approve and reject buttons remain for pending reservations. | Show both paths |
| 7 | Complaints | Complaints and concerns | Named Suggestions & Concerns, with suggestion, concern, and incident types | Use the actual name |
| 8 | Payment verification | Admin verification, "if implemented" | System verification only | Admin monitors. No verify step. |

### Other uncertainties

- Actual live database columns and foreign keys. A schema-only export would settle this.
- Whether a PayMongo webhook is registered for the deployed site.
- Whether `Admin/api/analytics.php` and `analytics_records.php` are still used. The pages reference `analytics_finance.php`.

Resolved during Phase 2: `homeowners.php` filters out `pending` accounts, so its archive action cannot remove a pending application. There is no way in the UI to reject or remove one.

### Technical risks noticed (no changes made)

- Google sign-in skips the account-status check that password login applies, as described in section 3.2.
- `config/db.php`, `config/oauth.php`, `config/paymongo.php`, and `includes/mail.php` are tracked by git. If any holds a real secret and the repository is shared, that secret should be rotated and removed from tracking.
- If a resident pays for a reservation but never returns to the success page, the reservation stays unpaid because the webhook handles dues only.
- `webhook.php` reads only the test-mode signature, so it would reject live-mode events.
- The tracked folder is `Admin/` with some capitalized files (`Admin/Dashboard.php`, `Admin/Homeowners.php`, `Admin/Reservations.php`), while the code links to lowercase `admin/dashboard.php`. This may break on a case-sensitive server unless the deployed names differ.

## 14. Source files inspected

- **Authentication:** `login.php`, `register.php`, `logout.php`, `index.php`, `admin/login.php`, `verify_otp.php`, `reset_password.php`, `oauth_callback.php`, `includes/auth_helpers.php`, `includes/otp.php`
- **Resident:** `resident/profile.php`, `payments.php`, `reservations.php`, `feedback.php`, `create_checkout.php`, `pay_reservation.php`, `payment_success.php`, `payment_cancel.php`, `verify_payment.php`, `webhook.php`, `payment_archive.php`
- **Admin:** `Admin/Dashboard.php`, `pending_homeowners.php`, `homeowners.php`, `homeowner_action.php`, `homeowner_view.php`, `reservations.php`, `payments.php`, `record_payment.php`, `feedback.php`, `announcements.php`, `send_reminder.php`, `report.php`, `analytics.php`, `manage_dues.php`, `view_receipt.php`, `Admin/api/analytics_finance.php`
- **Shared:** `includes/availability.php`, `payment_guard.php`, `payment_methods.php`, `archive_helpers.php`, `feedback.php`, `incident_helpers.php`, `notifications.php`, `notifications_api.php`, `homeowner_helpers.php`, `sidebar.php`, `settings.php`, `receipt.php`, `config/facilities.php`, `config/paymongo.php` (masked), `.htaccess`
- **Schema:** `manuela4b_mis.sql` at `HEAD` (structure only), `database/*.sql` structure statements, `sample_data.sql` and `remove_sample_data.sql` (search only)
- **Search only:** `config/oauth.php`, `OmniCopilot/package.json`, `Assets/js/admin-analytics.js`, `Assets/js/admin-dashboard-analytics.js`
