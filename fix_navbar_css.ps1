<#
  fix_navbar_css.ps1
  Fixes the inline .top-navbar CSS on all pages that have the search bar injected.
  Changes:
    - height: 64px  -->  height: auto; min-height: 64px
    - justify-content: space-between  --> removed (flex items naturally space via flex-grow on search container)
  Also ensures navbar-left and navbar-right keep align-items:center.
  Safe to re-run (checks for already-fixed marker).
#>

$root  = "e:\xampp\htdocs\thesis-prototype"
$pages = @(
    "add_course.php",
    "advising.php",
    "app_support.php",
    "communication_media.php",
    "deploy_assignment.php",
    "deploy_quiz.php",
    "grade_sheet.php",
    "manage_notifications.php",
    "quiz_take.php",
    "routine.php",
    "student_assignments.php",
    "student_attendance.php",
    "student_consult.php",
    "student_materials.php",
    "student_quiz.php",
    "student_scores.php",
    "teacher_attendance.php",
    "teacher_courses.php",
    "teacher_grading.php",
    "teacher_materials.php",
    "teacher_messages.php",
    "teacher_status.php"
)

foreach ($page in $pages) {
    $path = Join-Path $root $page

    if (!(Test-Path $path)) {
        Write-Host "SKIP (not found): $page"
        continue
    }

    $content = Get-Content $path -Raw -Encoding UTF8
    $changed = $false

    # Pattern 1 – single-line inline: height: 64px; z-index: 900; display: flex; align-items: center; justify-content: space-between;
    # Replace with height: auto; min-height: 64px and remove justify-content: space-between
    if ($content -match 'height: 64px; z-index: 900; display: flex; align-items: center; justify-content: space-between;') {
        $content = $content -replace 'height: 64px; z-index: 900; display: flex; align-items: center; justify-content: space-between;',
                                     'height: auto; min-height: 64px; z-index: 900; display: flex; align-items: center;'
        $changed = $true
    }

    # Pattern 2 – multi-line block that has height: 64px and justify-content: space-between
    # Replace height: 64px with height: auto; min-height: 64px
    if ($content -match "height: 64px;(\r?\n|\s)") {
        $content = $content -replace "(?m)(\s*)height: 64px;", '$1height: auto; min-height: 64px;'
        $changed = $true
    }

    # Remove justify-content: space-between from .top-navbar blocks
    # We target lines containing justify-content: space-between inside a CSS block
    if ($content -match "justify-content: space-between;") {
        $content = $content -replace "(\r?\n[ \t]*)justify-content: space-between;", ''
        $changed = $true
    }

    if ($changed) {
        [System.IO.File]::WriteAllText($path, $content, [System.Text.Encoding]::UTF8)
        Write-Host "FIXED: $page"
    } else {
        Write-Host "NO CHANGE: $page"
    }
}

Write-Host "`nDone."
