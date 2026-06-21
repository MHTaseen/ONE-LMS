<?php
// student_payment.php - View and download manual payment slip
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['role'] !== 'student') {
    header('Location: landing.php');
    exit();
}

require_once 'config.php';

$studentDbId = $_SESSION['user_pk'];
$fullName = $_SESSION['full_name'];
$studentIdNo = $_SESSION['user_id'];
$department = $_SESSION['department'];

// Check freeze status
$stmtF = $pdo->prepare("SELECT is_frozen FROM users WHERE id = ?");
$stmtF->execute([$studentDbId]);
$isFrozen = (bool)$stmtF->fetchColumn();

// Fetch active semester payment details
$paymentStatus = 'unpaid';
$paidAt = null;
$enrolledCourses = [];
$totalCredits = 0;
$totalFee = 0;
$paymentDeadline = null;
$deadlinePassed = false;
$deadlineDaysLeft = null;

if ($activeSemester) {
    $paymentDeadline = $activeSemester['payment_deadline'];
    if ($paymentDeadline) {
        $today = date('Y-m-d');
        $deadlinePassed = ($today > $paymentDeadline);
        
        $diff = strtotime($paymentDeadline) - strtotime($today);
        $deadlineDaysLeft = (int)round($diff / (60 * 60 * 24));
    }

    // Get current payment record
    $stmtPay = $pdo->prepare("SELECT status, paid_at FROM semester_payments WHERE student_id = ? AND semester_id = ? LIMIT 1");
    $stmtPay->execute([$studentDbId, $activeSemester['id']]);
    $payRow = $stmtPay->fetch();
    if ($payRow) {
        $paymentStatus = $payRow['status'];
        $paidAt = $payRow['paid_at'];
    }

    // Fetch enrolled courses
    $stmtCourses = $pdo->prepare("
        SELECT c.title, c.code, c.credit, c.course_fee, cs.section_no
        FROM enrollments e
        JOIN course_sections cs ON e.section_id = cs.id
        JOIN courses c ON cs.course_id = c.id
        WHERE e.student_id = ? AND e.semester_id = ?
    ");
    $stmtCourses->execute([$studentDbId, $activeSemester['id']]);
    $enrolledCourses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

    foreach ($enrolledCourses as $c) {
        $totalCredits += floatval($c['credit']);
        $totalFee += floatval($c['course_fee']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Academic Payment – BRAC University Hub</title>
    <link rel="stylesheet" href="style.css">
    <script src="theme.js"></script>
    <style>
        body { justify-content: flex-start; align-items: stretch; padding-top: 0; }
        
        .top-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: auto; min-height: 64px;
            z-index: 900; display: flex; align-items: center; padding: 10px 28px;
            background: var(--bg-secondary); border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            box-shadow: 0 2px 20px rgba(0,0,0,.25);
        }
        .navbar-left { display: flex; align-items: center; gap: 14px; }
        .navbar-brand {
            font-family: 'Space Grotesque', sans-serif; font-size: 1.15rem;
            font-weight: 700; background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            letter-spacing: -.5px; user-select: none;
        }
        .nav-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--gradient-accent); display: flex;
            justify-content: center; align-items: center; color: #fff;
            font-weight: 700; font-size: .95rem; box-shadow: var(--glow-shadow);
            cursor: default; font-family: 'Space Grotesque', sans-serif;
        }
        .navbar-right { display: flex; align-items: center; gap: 12px; }
        .theme-switch-container { position: static; }

        .page-wrap { padding: 120px 28px 60px; max-width: 1000px; margin: 0 auto; width: 100%; }

        .page-heading {
            font-family: 'Space Grotesque', sans-serif; font-size: 2rem;
            font-weight: 700; margin-bottom: 4px;
            background: var(--gradient-accent);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .page-subheading { color: var(--text-secondary); font-size: .95rem; margin-bottom: 30px; }

        /* Payment Summary Card */
        .payment-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--card-glow);
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
        }
        .payment-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: var(--gradient-accent);
        }

        .slip-preview-container {
            background: #fff;
            color: #111827;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            font-family: 'Inter', sans-serif;
            margin-top: 20px;
            border: 1px solid #e5e7eb;
        }

        .slip-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .slip-title {
            font-family: 'Space Grotesque', 'Inter', sans-serif;
            font-weight: 700;
            font-size: 1.4rem;
            color: #111827;
        }
        .slip-subtitle {
            font-size: 0.82rem;
            color: #4b5563;
            margin-top: 4px;
        }
        
        .slip-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 24px;
            font-size: 0.9rem;
        }
        .slip-meta-label {
            color: #6b7280;
            font-weight: 500;
        }
        .slip-meta-val {
            color: #111827;
            font-weight: 600;
        }

        .slip-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .slip-table th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #e5e7eb;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #4b5563;
        }
        .slip-table td {
            padding: 12px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.9rem;
            color: #1f2937;
        }
        .slip-table tr.total-row td {
            border-top: 2px solid #e5e7eb;
            border-bottom: none;
            font-weight: 700;
            font-size: 0.95rem;
            color: #111827;
        }

        .slip-footer {
            border-top: 1px dashed #d1d5db;
            padding-top: 20px;
            margin-top: 24px;
            font-size: 0.8rem;
            color: #4b5563;
            line-height: 1.5;
        }

        /* Print formatting */
        @media print {
            body {
                background: #white !important;
                color: #000 !important;
            }
            .ambient-glow-1, .ambient-glow-2, .top-navbar, .shared-drawer-section, .btn-print, .non-printable {
                display: none !important;
            }
            .page-wrap {
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            .slip-preview-container {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                background: white !important;
                color: black !important;
            }
        }
    </style>
    <link rel="stylesheet" href="responsive.css?v=3">
</head>
<body>
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <?php include 'includes/global_nav.php'; ?>
    <?php include 'includes/shared_drawer.php'; ?>

    <div class="page-wrap">
        <div class="non-printable">
            <h1 class="page-heading">Semester Payment</h1>
            <p class="page-subheading">View, download and print your semester course registration fee invoice.</p>

            <!-- Freeze Lock warning -->
            <?php if ($isFrozen): ?>
                <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid #ef4444; color: #fff; padding: 20px; border-radius: 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 15px; box-shadow: 0 4px 20px rgba(239, 68, 68, 0.2);">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" style="flex-shrink:0;">
                        <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                    </svg>
                    <div>
                        <h3 style="color: #ef4444; font-family: 'Space Grotesque', sans-serif; font-size: 1.05rem; margin-bottom: 4px;">Account Frozen</h3>
                        <p style="font-size: 0.88rem; color: var(--text-secondary); line-height: 1.45;">
                            Your student portal access has been locked due to unpaid semester fees after the deadline. 
                            Please print this slip, pay manually at a BRAC Bank branch, and submit receipt proof to the Administrative office to unfreeze.
                        </p>
                    </div>
                </div>
            <?php elseif ($paymentStatus === 'unpaid' && $paymentDeadline): ?>
                <!-- Unpaid deadline approaching warning -->
                <div style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.3); color: #fff; padding: 18px; border-radius: 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 15px;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" style="flex-shrink:0;">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <div>
                        <h3 style="color: #f59e0b; font-family: 'Space Grotesque', sans-serif; font-size: 1rem; margin-bottom: 2px;">Payment Due</h3>
                        <p style="font-size: 0.88rem; color: var(--text-secondary); line-height: 1.4;">
                            Deadline for manually submitting semester fee is <strong><?= date('F j, Y', strtotime($paymentDeadline)) ?></strong>.
                            <?php if ($deadlineDaysLeft > 0): ?>
                                You have <strong><?= $deadlineDaysLeft ?> days</strong> remaining to pay.
                            <?php elseif ($deadlineDaysLeft === 0): ?>
                                Today is the last date to clear the payment!
                            <?php else: ?>
                                Overdue by <strong><?= abs($deadlineDaysLeft) ?> days</strong>. Pay immediately to prevent account freeze.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php elseif ($paymentStatus === 'paid'): ?>
                <!-- Success message -->
                <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); color: #fff; padding: 18px; border-radius: 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 15px;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" style="flex-shrink:0;">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <div>
                        <h3 style="color: #10b981; font-family: 'Space Grotesque', sans-serif; font-size: 1rem; margin-bottom: 2px;">Semester Paid</h3>
                        <p style="font-size: 0.88rem; color: var(--text-secondary); line-height: 1.4;">
                            Your semester fee payment was successfully processed on <?= date('F j, Y, g:i A', strtotime($paidAt)) ?>. No dues pending.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$activeSemester): ?>
            <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:20px; padding:30px; text-align:center; color:var(--text-secondary);">
                Active semester context is missing. Contact site administrators.
            </div>
        <?php elseif (empty($enrolledCourses)): ?>
            <div style="background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:20px; padding:40px; text-align:center; color:var(--text-secondary);">
                <h3>No Registered Courses</h3>
                <p style="margin-top:8px; font-size:0.9rem;">You have not registered for any courses in the <?= htmlspecialchars($activeSemester['label']) ?> semester. Complete your Advising first.</p>
            </div>
        <?php else: ?>

            <div class="non-printable" style="display:flex; justify-content:flex-end; margin-bottom:15px;">
                <button onclick="window.print()" class="btn-primary btn-print" style="background:var(--gradient-accent); display:flex; align-items:center; gap:8px; border:none; padding:10px 20px; border-radius:12px; font-weight:700; color:#white; cursor:pointer;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    Print / Download Payment Slip
                </button>
            </div>

            <!-- Print Slip Block -->
            <div class="slip-preview-container print-slip-area">
                <div class="slip-header">
                    <div>
                        <div class="slip-title">BRAC UNIVERSITY</div>
                        <div class="slip-subtitle">LMS Academic Semester Payment Slip</div>
                    </div>
                    <div style="text-align: right; font-size: 0.8rem; color: #4b5563;">
                        <div>Invoice: #PAY-<?= $activeSemester['id'] ?>-<?= $studentDbId ?></div>
                        <div>Date: <?= date('F j, Y') ?></div>
                    </div>
                </div>

                <div class="slip-meta-grid">
                    <div>
                        <span class="slip-meta-label">Student Name:</span>
                        <span class="slip-meta-val"><?= htmlspecialchars($fullName) ?></span>
                    </div>
                    <div>
                        <span class="slip-meta-label">Student ID:</span>
                        <span class="slip-meta-val"><?= htmlspecialchars($studentIdNo) ?></span>
                    </div>
                    <div>
                        <span class="slip-meta-label">Department:</span>
                        <span class="slip-meta-val"><?= htmlspecialchars($department) ?></span>
                    </div>
                    <div>
                        <span class="slip-meta-label">Semester:</span>
                        <span class="slip-meta-val"><?= htmlspecialchars($activeSemester['label']) ?></span>
                    </div>
                </div>

                <table class="slip-table">
                    <thead>
                        <tr>
                            <th>Course Code</th>
                            <th>Course Title</th>
                            <th>Section</th>
                            <th style="text-align: center;">Credit</th>
                            <th style="text-align: right;">Fee (TK)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrolledCourses as $course): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($course['code']) ?></strong></td>
                                <td><?= htmlspecialchars($course['title']) ?></td>
                                <td>Sec <?= str_pad($course['section_no'], 2, '0', STR_PAD_LEFT) ?></td>
                                <td style="text-align: center;"><?= number_format($course['credit'], 1) ?></td>
                                <td style="text-align: right;"><?= number_format($course['course_fee'], 2) ?> TK</td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <tr class="total-row">
                            <td colspan="3" style="text-align: right;">Totals:</td>
                            <td style="text-align: center;"><?= number_format($totalCredits, 1) ?> Cr</td>
                            <td style="text-align: right;"><?= number_format($totalFee, 2) ?> TK</td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top: 30px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 40px;">
                    <div>
                        <h4 style="font-size:0.85rem; font-weight:700; color:#111827; margin-bottom:8px; text-transform:uppercase;">Bank deposit instruction</h4>
                        <p style="font-size:0.75rem; color:#4b5563; line-height:1.4;">
                            Fee can be paid at any branch of <strong>BRAC Bank Limited</strong> or <strong>Prime Bank Limited</strong> using this deposit slip. 
                            Submit the bank deposit receipt counter-foil to the accounts department to clear database dues.
                        </p>
                    </div>
                    <div style="display:flex; flex-direction:column; justify-content:flex-end; align-items:flex-end;">
                        <div style="border-bottom:1px solid #9ca3af; width:180px; margin-bottom:6px;"></div>
                        <span style="font-size:0.75rem; color:#6b7280; font-weight:500;">Accounts Officer Signature</span>
                    </div>
                </div>

                <div class="slip-footer">
                    <div style="border-top: 1px solid #e5e7eb; padding-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                        <span>System Generated document. No physical stamp required unless queried.</span>
                        <strong style="color: #111827;">Status: <?= strtoupper($paymentStatus) ?></strong>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>
<?php include 'includes/global_search_js.php'; ?>
</body>
</html>
