<#
  patch_search_bar.ps1
  Injects the universal search bar into every page that has a top-navbar.
  - Adds  global-search-nav  class to the <nav class="top-navbar"> element
  - Inserts <?php include 'includes/global_search.php'; ?> between navbar-left and navbar-right divs
  - Inserts <?php include 'includes/global_search_js.php'; ?> before </body>
  Safe to re-run: skips files that are already patched.
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

$searchInclude = "        <?php include 'includes/global_search.php'; ?>"
$jsInclude     = "<?php include 'includes/global_search_js.php'; ?>"

foreach ($page in $pages) {
    $path = Join-Path $root $page

    if (!(Test-Path $path)) {
        Write-Host "SKIP (not found): $page"
        continue
    }

    $content = Get-Content $path -Raw -Encoding UTF8

    # --- Guard: already patched? ---
    if ($content -match "global_search\.php") {
        Write-Host "SKIP (already patched): $page"
        continue
    }

    $changed = $false

    # 1. Add global-search-nav class to top-navbar
    if ($content -match 'class="top-navbar"') {
        $content = $content -replace 'class="top-navbar"', 'class="top-navbar global-search-nav"'
        $changed = $true
    }

    # 2. Insert search bar include BEFORE the closing </div> of navbar-left
    #    Pattern: </div>\s*\n\s*<div class="navbar-right">
    #    We insert the search include between the two divs.
    $navbarPattern = '(</div>\s*\r?\n)(\s*<div class="navbar-right">)'
    if ($content -match $navbarPattern) {
        $content = $content -replace $navbarPattern, "`$1$searchInclude`n`$2"
        $changed = $true
    }

    # 3. Insert JS include before </body>
    if ($content -notmatch [regex]::Escape($jsInclude)) {
        $content = $content -replace '</body>', "$jsInclude`n</body>"
        $changed = $true
    }

    if ($changed) {
        [System.IO.File]::WriteAllText($path, $content, [System.Text.Encoding]::UTF8)
        Write-Host "PATCHED: $page"
    } else {
        Write-Host "NO CHANGE: $page"
    }
}

Write-Host "`nDone."
