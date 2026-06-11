<?php
$drawerRole = $_SESSION['role'] ?? '';
$drawerAdvisingOpen = false;
if (isset($pdo) && $drawerRole === 'teacher') {
    $stmt_adv = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'advising_open'");
    $advisingOpenRow = $stmt_adv ? $stmt_adv->fetch() : null;
    $drawerAdvisingOpen = $advisingOpenRow ? ($advisingOpenRow['setting_value'] === '1') : false;
}
?>
<style>
    .shared-drawer-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        top: 56px;
        background: rgba(7, 11, 20, 0.65);
        backdrop-filter: blur(2px);
        z-index: 790;
    }

    .shared-drawer-backdrop.visible {
        display: block;
    }

    .shared-drawer-section {
        position: fixed;
        top: 64px;
        left: 0;
        z-index: 800;
    }

    .shared-drawer-btn {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 14px 0 0 20px;
        padding: 9px 18px;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        border-radius: 12px;
        cursor: pointer;
        font-size: 0.9rem;
        font-weight: 600;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        box-shadow: var(--card-glow);
        transition: border-color 0.25s, box-shadow 0.25s, transform 0.2s;
    }

    .shared-drawer-btn:hover {
        border-color: var(--accent-primary);
        box-shadow: var(--glow-shadow);
        transform: translateY(-2px);
    }

    .shared-drawer-btn.open {
        border-color: var(--accent-primary);
        box-shadow: var(--glow-shadow);
    }

    .shared-hamburger-icon {
        display: flex;
        flex-direction: column;
        gap: 5px;
        width: 20px;
    }

    .shared-hamburger-icon span {
        display: block;
        height: 2px;
        border-radius: 2px;
        background: var(--text-primary);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        transform-origin: center;
    }

    .shared-drawer-btn.open .shared-hamburger-icon span:nth-child(1) {
        transform: translateY(7px) rotate(45deg);
    }

    .shared-drawer-btn.open .shared-hamburger-icon span:nth-child(2) {
        opacity: 0;
        transform: scaleX(0);
    }

    .shared-drawer-btn.open .shared-hamburger-icon span:nth-child(3) {
        transform: translateY(-7px) rotate(-45deg);
    }

    .shared-drawer-dropdown {
        position: absolute;
        top: calc(100% + 6px);
        left: 20px;
        width: 270px;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 8px;
        padding-bottom: 22px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35), var(--glow-shadow);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        max-height: calc(100vh - 140px);
        overflow-y: auto;
        overflow-x: hidden;
        opacity: 0;
        transform: translateY(-12px) scale(0.97);
        pointer-events: none;
        transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1), transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .shared-drawer-dropdown.visible {
        opacity: 1;
        transform: translateY(0) scale(1);
        pointer-events: all;
    }

    .shared-drawer-dropdown::before {
        content: '';
        position: absolute;
        top: -1px;
        left: 0;
        right: 0;
        height: 3px;
        background: var(--gradient-accent);
        border-radius: 16px 16px 0 0;
    }

    .shared-drawer-dropdown::-webkit-scrollbar {
        width: 5px;
    }

    .shared-drawer-dropdown::-webkit-scrollbar-track {
        background: transparent;
    }

    .shared-drawer-dropdown::-webkit-scrollbar-thumb {
        background: var(--accent-primary);
        border-radius: 99px;
        opacity: 0.5;
    }

    .shared-drawer-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 10px;
        cursor: pointer;
        color: var(--text-primary);
        font-size: 0.92rem;
        font-weight: 500;
        border: none;
        background: transparent;
        width: 100%;
        text-align: left;
        text-decoration: none;
        transition: background 0.2s, color 0.2s, transform 0.15s;
    }

    .shared-drawer-item:hover {
        background: rgba(168, 85, 247, 0.1);
        color: var(--accent-primary);
        transform: translateX(4px);
    }

    .shared-drawer-item svg {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
        color: var(--accent-secondary);
        transition: color 0.2s;
    }

    .shared-drawer-item:hover svg {
        color: var(--accent-primary);
    }

    .shared-drawer-divider {
        height: 1px;
        background: var(--border-color);
        margin: 6px 8px;
    }

    .shared-drawer-section-label {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-secondary);
        padding: 10px 14px 4px;
        display: block;
    }

    @media (max-width: 768px) {
        .shared-drawer-section {
            position: fixed;
            top: 10px;
            left: 58px;
            z-index: 925;
            margin: 0;
        }

        .shared-drawer-btn {
            margin: 0 !important;
            padding: 8px 12px;
            font-size: 0.85rem;
            box-shadow: 0 2px 14px rgba(0, 0, 0, 0.35);
        }

        .shared-drawer-backdrop {
            top: 64px;
            z-index: 905;
        }

        .shared-drawer-dropdown {
            position: fixed !important;
            top: 64px !important;
            left: 0 !important;
            right: auto !important;
            bottom: 0 !important;
            width: min(300px, 88vw) !important;
            max-height: none !important;
            height: calc(100dvh - 64px) !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            border-radius: 0 16px 16px 0 !important;
            z-index: 910 !important;
            transform: translateX(-105%) !important;
            opacity: 1 !important;
            pointer-events: none;
            transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .shared-drawer-dropdown.visible {
            transform: translateX(0) !important;
            pointer-events: all;
        }
    }

    @media (max-width: 600px) {
        .shared-drawer-section {
            top: 9px;
            left: 50px;
        }

        .shared-drawer-btn {
            padding: 7px 10px;
            font-size: 0.8rem;
        }

        .shared-drawer-dropdown {
            width: min(320px, 92vw) !important;
        }

        .shared-drawer-item {
            font-size: 0.88rem;
            padding: 11px 12px;
        }

        .shared-drawer-section-label {
            font-size: 0.65rem;
            padding: 8px 12px 4px;
        }
    }
</style>

<div class="shared-drawer-backdrop" id="sharedDrawerBackdrop" aria-hidden="true"></div>

<div class="shared-drawer-section" id="sharedDrawerSection">
    <button class="shared-drawer-btn" id="sharedDrawerToggleBtn" aria-label="Open navigation menu" aria-expanded="false">
        <div class="shared-hamburger-icon" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <span class="shared-drawer-btn-label">Menu</span>
    </button>

    <div class="shared-drawer-dropdown" id="sharedDrawerDropdown" role="menu">
        <span class="shared-drawer-section-label">Account</span>
        <a href="landing.php" class="shared-drawer-item" role="menuitem">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 12l9-9 9 9"></path>
                <path d="M9 21V9h6v12"></path>
            </svg>
            Dashboard
        </a>

        <div class="shared-drawer-divider"></div>

        <?php if ($drawerRole === 'student' || $drawerRole === 'guest'): ?>
            <span class="shared-drawer-section-label">Academic</span>
            <a href="student_quiz.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4m0 0L8 8m4-4l4 4"/><path d="M12 16c-3.5 0-6 2-6 4v2h12v-2c0-2-2.5-4-6-4z"/></svg>
                My Quiz
            </a>
            <a href="grade_sheet.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                Grade Sheet
            </a>
            <a href="routine.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                Routine
            </a>
            <?php if ($drawerRole !== 'guest'): ?>
                <a href="advising.php" class="shared-drawer-item" role="menuitem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    Advising
                </a>
                <a href="student_consult.php" class="shared-drawer-item" role="menuitem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    Consult with Teacher
                </a>
            <?php endif; ?>
            <a href="student_assignments.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                    <polyline points="10 9 9 9 8 9"/>
                </svg>
                My Assignments
            </a>
            <a href="student_materials.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                Course Materials
            </a>
            <?php if ($drawerRole !== 'guest'): ?>
                <a href="communication_media.php" class="shared-drawer-item" role="menuitem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    Central Communication Media
                </a>
                <a href="manage_notifications.php" class="shared-drawer-item" role="menuitem">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    Manage Notifications
                </a>
            <?php endif; ?>
            <a href="student_scores.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                Current Score
            </a>
            <a href="student_attendance.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                    <path d="M8 14h.01"></path><path d="M12 14h.01"></path><path d="M16 14h.01"></path><path d="M8 18h.01"></path><path d="M12 18h.01"></path><path d="M16 18h.01"></path>
                </svg>
                Attendance
            </a>
            <a href="app_support.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polygon points="10 8 16 12 10 16 10 8"></polygon>
                </svg>
                App Support
            </a>
        <?php elseif ($drawerRole === 'teacher'): ?>
            <span class="shared-drawer-section-label">Faculty Panel</span>
            <a href="add_course.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Add Course
            </a>
            <a href="teacher_messages.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                Student messages
            </a>
            <a href="teacher_materials.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                    <polyline points="13 2 13 9 20 9"/>
                    <line x1="12" y1="11" x2="12" y2="17"/>
                    <line x1="9" y1="14" x2="15" y2="14"/>
                </svg>
                Add Course Materials
            </a>
            <a href="communication_media.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                Central Communication Media
            </a>
            <a href="manage_notifications.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                </svg>
                Manage Notifications
            </a>
            <a href="teacher_grading.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                Submit Current Score
            </a>
            <a href="teacher_attendance.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Attendance
            </a>
            <a href="teacher_courses.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                Courses
            </a>
            <a href="teacher_status.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Course Status
            </a>
            <a href="deploy_assignment.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Deploy Assignments
            </a>
            <a href="deploy_quiz.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                Deploy Quiz
            </a>
            <div class="shared-drawer-item shared-preference-item" role="menuitem">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent-primary); width: 18px; height: 18px;">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span>Open Advising Portal</span>
                </div>
                <label class="switch-toggle">
                    <input type="checkbox" id="advisingToggleBtnShared" <?= $drawerAdvisingOpen ? 'checked' : '' ?>>
                    <span class="switch-slider"></span>
                </label>
            </div>
            <a href="app_support.php" class="shared-drawer-item" role="menuitem">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polygon points="10 8 16 12 10 16 10 8"></polygon>
                </svg>
                App Support
            </a>
        <?php endif; ?>

        <div class="shared-drawer-divider"></div>
        <span class="shared-drawer-section-label">Accessibility</span>

        <div class="shared-drawer-item shared-preference-item" role="menuitem">
            <div style="display: flex; align-items: center; gap: 12px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M7 12h10"/>
                    <path d="M12 7v10"/>
                </svg>
                <span>High Contrast Mode</span>
            </div>
            <label class="switch-toggle">
                <input type="checkbox" id="highContrastToggleBtnShared">
                <span class="switch-slider"></span>
            </label>
        </div>

        <div class="shared-drawer-item shared-preference-item" role="menuitem">
            <div style="display: flex; align-items: center; gap: 12px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14"/>
                    <path d="M12 5l7 7-7 7"/>
                </svg>
                <span>Reduce Motion</span>
            </div>
            <label class="switch-toggle">
                <input type="checkbox" id="reduceMotionToggleBtnShared">
                <span class="switch-slider"></span>
            </label>
        </div>

        <div class="shared-drawer-item shared-preference-item" role="menuitem">
            <div style="display: flex; align-items: center; gap: 12px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="4" y="4" width="16" height="16" rx="2"/>
                    <path d="M9 9h6v6H9z"/>
                </svg>
                <span>Keyboard Focus States</span>
            </div>
            <label class="switch-toggle">
                <input type="checkbox" id="keyboardFocusToggleBtnShared">
                <span class="switch-slider"></span>
            </label>
        </div>

        <div class="shared-drawer-item shared-preference-item" role="menuitem">
            <div style="display: flex; align-items: center; gap: 12px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/>
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                    <path d="M12 19v2"/>
                </svg>
                <span>Text to speech</span>
            </div>
            <label class="switch-toggle">
                <input type="checkbox" id="textToSpeechToggleBtnShared">
                <span class="switch-slider"></span>
            </label>
        </div>

        <div class="shared-drawer-item shared-preference-item" role="menuitem">
            <div style="display: flex; align-items: center; gap: 12px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                <span>English/Bangla</span>
            </div>
            <label class="switch-toggle">
                <input type="checkbox" id="langToggleBtn">
                <span class="switch-slider"></span>
            </label>
        </div>

        <div class="shared-drawer-item shared-preference-item" role="menuitem">
            <div style="display: flex; align-items: center; gap: 12px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>Dyslexia-friendly Font</span>
            </div>
            <label class="switch-toggle">
                <input type="checkbox" id="dyslexiaToggleBtn">
                <span class="switch-slider"></span>
            </label>
        </div>

        <div class="shared-drawer-divider"></div>
        <a href="logout.php" class="shared-drawer-item" role="menuitem" style="color: #ef4444;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Log Out
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const drawerBtn = document.getElementById('sharedDrawerToggleBtn');
    const drawerDropdown = document.getElementById('sharedDrawerDropdown');
    const drawerBackdrop = document.getElementById('sharedDrawerBackdrop');

    if (!drawerBtn || !drawerDropdown) return;

    const drawerLabel = drawerBtn.querySelector('.shared-drawer-btn-label');
    const isMobileDrawer = window.matchMedia('(max-width: 768px)');

    function openDrawer() {
        drawerDropdown.classList.add('visible');
        drawerBtn.classList.add('open');
        drawerBtn.setAttribute('aria-expanded', 'true');
        drawerBtn.setAttribute('aria-label', 'Close navigation menu');
        if (drawerLabel) drawerLabel.textContent = 'Close';
        if (drawerBackdrop && isMobileDrawer.matches) drawerBackdrop.classList.add('visible');
        document.body.classList.add('drawer-open');
    }

    function closeDrawer() {
        drawerDropdown.classList.remove('visible');
        drawerBtn.classList.remove('open');
        drawerBtn.setAttribute('aria-expanded', 'false');
        drawerBtn.setAttribute('aria-label', 'Open navigation menu');
        if (drawerLabel) drawerLabel.textContent = 'Menu';
        if (drawerBackdrop) drawerBackdrop.classList.remove('visible');
        document.body.classList.remove('drawer-open');
    }

    drawerBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        drawerDropdown.classList.contains('visible') ? closeDrawer() : openDrawer();
    });

    if (drawerBackdrop) {
        drawerBackdrop.addEventListener('click', closeDrawer);
    }

    drawerDropdown.querySelectorAll('a.shared-drawer-item').forEach((link) => {
        link.addEventListener('click', closeDrawer);
    });

    document.addEventListener('click', (e) => {
        const section = document.getElementById('sharedDrawerSection');
        if (section && !section.contains(e.target) && !drawerBackdrop?.contains(e.target)) {
            closeDrawer();
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeDrawer();
        }
    });

    document.querySelectorAll('.shared-preference-item').forEach((item) => {
        item.addEventListener('click', (e) => e.stopPropagation());
    });

    if (isMobileDrawer && isMobileDrawer.addEventListener) {
        isMobileDrawer.addEventListener('change', () => {
            if (!isMobileDrawer.matches && drawerBackdrop) drawerBackdrop.classList.remove('visible');
        });
    }

    // Advising Portal Toggle for Shared Drawer
    const advisingToggleBtnShared = document.getElementById('advisingToggleBtnShared');
    if (advisingToggleBtnShared) {
        advisingToggleBtnShared.addEventListener('change', function() {
            const isOpen = this.checked;
            fetch('toggle_advising.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ advising_open: isOpen })
            })
            .then(response => response.json())
            .then(data => {
                if(!data.success) {
                    alert(data.message || 'Failed to toggle advising state.');
                    this.checked = !isOpen; // Revert
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred.');
                this.checked = !isOpen; // Revert
            });
        });
    }
});
</script>
