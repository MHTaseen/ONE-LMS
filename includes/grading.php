<?php
// includes/grading.php – shared letter-grade / grade-point helpers

function getGradeInfo($score) {
    if ($score === null) {
        return ['letter' => 'Pending', 'point' => null];
    }
    $score = floatval($score);
    if ($score >= 90) return ['letter' => 'A',   'point' => 4.0];
    if ($score >= 85) return ['letter' => 'A-',  'point' => 3.7];
    if ($score >= 80) return ['letter' => 'B+',  'point' => 3.3];
    if ($score >= 75) return ['letter' => 'B',   'point' => 3.0];
    if ($score >= 70) return ['letter' => 'B-',  'point' => 2.7];
    if ($score >= 65) return ['letter' => 'C+',  'point' => 2.3];
    if ($score >= 60) return ['letter' => 'C',   'point' => 2.0];
    if ($score >= 57) return ['letter' => 'C-',  'point' => 1.7];
    if ($score >= 55) return ['letter' => 'D+',  'point' => 1.3];
    if ($score >= 52) return ['letter' => 'D',   'point' => 1.0];
    if ($score >= 50) return ['letter' => 'D-',  'point' => 0.7];
    return ['letter' => 'F', 'point' => 0.0];
}

function teacherOwnsSection(PDO $pdo, int $teacherDbId, int $sectionId): bool {
    $stmt = $pdo->prepare("
        SELECT cs.id FROM course_sections cs
        JOIN courses c ON cs.course_id = c.id
        WHERE cs.id = ? AND (c.teacher_id = ? OR cs.teacher_id = ?)
    ");
    $stmt->execute([$sectionId, $teacherDbId, $teacherDbId]);
    return (bool) $stmt->fetch();
}

function computeEnrollmentTotal(array $row): ?int {
    if ($row['score_final'] === null) {
        return null;
    }
    return intval($row['score_attendance'] ?? 0)
         + intval($row['score_mid']        ?? 0)
         + intval($row['score_final'])
         + intval($row['score_lab']        ?? 0);
}
