<?php
require_once 'config/init.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms and Conditions - Manuela 4B MIS</title>
    <link rel="icon" type="image/png" href="Assets/css/images/logo2.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="Assets/css/global.css?v=21" rel="stylesheet">
    <link href="Assets/css/components.css?v=22" rel="stylesheet">
    <link href="Assets/css/reference-theme.css?v=25" rel="stylesheet">
    <link href="Assets/css/ui-refresh.css?v=1" rel="stylesheet">
    <style>
        body {
            background-color: var(--ref-bg, #0B1220);
            color: var(--ref-text, #E2E8F0);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            padding: 40px 16px;
        }
        .terms-container {
            max-width: 860px;
            margin: 0 auto;
        }
        .terms-card {
            background-color: var(--ref-card, #111A2E);
            border: 1px solid var(--ref-border, rgba(255, 255, 255, 0.08));
            border-radius: 16px;
            padding: 40px 36px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }
        .terms-header {
            border-bottom: 1px solid var(--ref-border, rgba(255, 255, 255, 0.08));
            padding-bottom: 24px;
            margin-bottom: 28px;
        }
        .terms-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        .terms-logo {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: rgba(129, 140, 248, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #818CF8;
            font-size: 1.1rem;
        }
        .terms-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #FFFFFF;
            margin: 0 0 4px;
        }
        .terms-date {
            font-size: 0.85rem;
            color: #94A3B8;
        }
        .terms-section {
            margin-bottom: 28px;
        }
        .terms-section h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #818CF8;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .terms-section p, .terms-section li {
            font-size: 0.92rem;
            line-height: 1.7;
            color: #CBD5E1;
            margin-bottom: 10px;
        }
        .terms-section ul {
            padding-left: 20px;
            margin-bottom: 10px;
        }
        .terms-badge {
            display: inline-block;
            background: rgba(129, 140, 248, 0.12);
            color: #818CF8;
            border: 1px solid rgba(129, 140, 248, 0.25);
            border-radius: 6px;
            padding: 2px 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .terms-actions {
            border-top: 1px solid var(--ref-border, rgba(255, 255, 255, 0.08));
            padding-top: 24px;
            margin-top: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn-brand-outline {
            color: #818CF8;
            border: 1px solid rgba(129, 140, 248, 0.4);
            background: transparent;
            border-radius: 8px;
            padding: 8px 18px;
            font-size: 0.88rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-brand-outline:hover {
            background: rgba(129, 140, 248, 0.15);
            color: #FFFFFF;
            border-color: #818CF8;
        }
        .btn-brand-primary {
            background: #818CF8;
            color: #070C18;
            font-weight: 600;
            border-radius: 8px;
            padding: 8px 20px;
            font-size: 0.88rem;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .btn-brand-primary:hover {
            background: #6366F1;
            color: #FFFFFF;
        }
    </style>
</head>
<body>
    <div class="terms-container">
        <div class="terms-card">
            
            <!-- HEADER -->
            <div class="terms-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div class="terms-brand">
                        <div class="terms-logo">4B</div>
                        <div>
                            <div class="fw-bold text-white lh-1">Manuela 4B</div>
                            <small class="text-secondary" style="font-size: 0.75rem;">HOA Management System</small>
                        </div>
                    </div>
                    <span class="terms-badge"><i class="bi bi-shield-check me-1"></i> Official Policy</span>
                </div>
                <h1 class="terms-title">Terms and Conditions</h1>
                <div class="terms-date">
                    <i class="bi bi-calendar3 me-1"></i> Effective Date: October 26, 2026 &nbsp;&bull;&nbsp; Version 1.0
                </div>
            </div>

            <!-- SECTION 1 -->
            <div class="terms-section">
                <h3><i class="bi bi-1-circle me-1"></i> Acceptance of Terms</h3>
                <p>Welcome to the <strong>Manuela 4B Homeowners Association Management Information System (MIS)</strong>. By accessing, registering an account with, or utilizing any services provided on this platform (including web pages located at <code>manuela4b.infy.click</code> or associated subdivision portals), you agree to comply with and be bound by these Terms and Conditions and our community rules. If you do not agree to these terms, please do not use this portal.</p>
            </div>

            <!-- SECTION 2 -->
            <div class="terms-section">
                <h3><i class="bi bi-2-circle me-1"></i> Eligibility & Account Registration</h3>
                <p>Access to the resident portal is restricted exclusively to recognized property owners, designated tenants, and authorized household representatives of the Manuela 4B Subdivision.</p>
                <ul>
                    <li><strong>Accurate Information:</strong> You agree to provide true, accurate, current, and complete details regarding your Block, Lot, contact number, and personal identity during registration.</li>
                    <li><strong>Identity Verification:</strong> To safeguard the community against unauthorized access, all registrations require the submission of a valid government-issued ID or recognized homeowner identification. Accounts remain in pending status until reviewed and validated by the HOA Administration.</li>
                    <li><strong>Account Security:</strong> You are solely responsible for maintaining the confidentiality of your account credentials (including your password and one-time passwords/OTP). Notify the HOA administration immediately of any unauthorized account activity.</li>
                </ul>
            </div>

            <!-- SECTION 3 -->
            <div class="terms-section">
                <h3><i class="bi bi-3-circle me-1"></i> Monthly Association Dues & Online Payments</h3>
                <p>The Manuela 4B MIS provides digital ledger tracking and online payment facilities to simplify dues management for the community.</p>
                <ul>
                    <li><strong>Assessment:</strong> Regular monthly association dues are assessed based on current HOA board resolutions and rates approved by the general assembly.</li>
                    <li><strong>Payment Gateways:</strong> Digital payments through GCash, Maya, and supported cards are facilitated via third-party payment partner <strong>PayMongo</strong>. The Association does not store sensitive payment credentials such as card CVVs or bank PINs.</li>
                    <li><strong>Official Receipts:</strong> Upon automated verification or manual administrative validation, an electronic receipt number will be issued and permanently logged in your resident ledger.</li>
                    <li><strong>Discrepancies:</strong> Payment adjustments or inquiries must be reported to the HOA treasurer or administration within thirty (30) days of the transaction date accompanied by the corresponding transaction reference.</li>
                </ul>
            </div>

            <!-- SECTION 4 -->
            <div class="terms-section">
                <h3><i class="bi bi-4-circle me-1"></i> Facility Reservations & Amenity Use</h3>
                <p>The portal allows residents to schedule use of common subdivision facilities (e.g., clubhouse, sports facilities).</p>
                <ul>
                    <li><strong>Booking Policies:</strong> All reservations are subject to availability, schedule conflicts, and formal administrative approval. Priority is given to verified residents in good standing.</li>
                    <li><strong>User Responsibility:</strong> The reserving resident is personally responsible for adhering to designated hours, noise ordinances, clean-as-you-go rules, and any damages incurred during their reserved timeframe.</li>
                    <li><strong>Cancellations:</strong> Cancellations should be filed through the system at least forty-eight (48) hours prior to the booked schedule.</li>
                </ul>
            </div>

            <!-- SECTION 5 -->
            <div class="terms-section">
                <h3><i class="bi bi-5-circle me-1"></i> Data Privacy & Protection (RA 10173)</h3>
                <p>The Manuela 4B Homeowners Association values and respects your fundamental right to privacy in strict compliance with the <strong>Philippine Data Privacy Act of 2012 (Republic Act No. 10173)</strong>.</p>
                <ul>
                    <li><strong>Data Collected:</strong> Personal identification data (full name, email, contact number, emergency contact, proof of residency/ID image, and payment records).</li>
                    <li><strong>Purpose:</strong> Data is collected solely for homeowner verification, community billing, emergency communications, association announcements, and official HOA records.</li>
                    <li><strong>Confidentiality:</strong> Your personal data is never sold, leased, or distributed to commercial advertisers or unassociated third parties. Access is strictly restricted to authorized association officers.</li>
                    <li><strong>Storage:</strong> Verification documents are stored in protected private server directories with restricted file access permissions.</li>
                </ul>
            </div>

            <!-- SECTION 6 -->
            <div class="terms-section">
                <h3><i class="bi bi-6-circle me-1"></i> Acceptable Use & Conduct</h3>
                <p>Users agree not to misuse the portal or post content that is defamatory, abusive, fraudulent, or harmful to fellow homeowners or the subdivision administration. Any deliberate attempts to compromise the portal's security, reverse engineer services, spam notification systems, or forge payment receipts will result in immediate account revocation and appropriate legal remedies.</p>
            </div>

            <!-- SECTION 7 -->
            <div class="terms-section">
                <h3><i class="bi bi-7-circle me-1"></i> Modifications & Contact Information</h3>
                <p>The HOA Board of Directors reserves the right to amend these Terms and Conditions to reflect changing association bylaws, operational requirements, or legal mandates. Continued use of the portal after modifications indicates acceptance of updated terms.</p>
                <p class="mb-0">For questions, account assistance, or data privacy inquiries, contact the <strong>Manuela 4B HOA Administrative Office</strong> or submit an inquiry through the Resident Feedback module.</p>
            </div>

            <!-- FOOTER ACTIONS -->
            <div class="terms-actions">
                <a href="register.php" class="btn-brand-outline">
                    <i class="bi bi-arrow-left me-1"></i> Back to Registration
                </a>
                <a href="login.php" class="btn-brand-primary">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Proceed to Login
                </a>
            </div>

        </div>
    </div>
</body>
</html>

