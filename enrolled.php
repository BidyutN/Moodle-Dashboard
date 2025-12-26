<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Faculty Enrollment Details Report
 * @package local_amizone
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
if (!isloggedin()) {
    redirect(get_login_url());
}

require_once($CFG->dirroot . '/local/amizone/locallib.php');
$amizone = new amizone();
global $DB, $PAGE, $OUTPUT, $USER;

// Get parameters
$facultyName = required_param('faculty', PARAM_TEXT);
$selectedCity = optional_param('city', '', PARAM_TEXT);

// Set up page
$PAGE->set_url(new moodle_url("/local/amizone/enrolled.php", ['faculty' => $facultyName, 'city' => $selectedCity]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Enrollment Details - " . $facultyName);
$PAGE->set_heading("Enrollment Details - " . $facultyName);

// Check permissions
if (!($USER->usertype == 'HOI' || $USER->usertype == 'Faculty' || is_siteadmin())) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermissions', 'error', 'access this page'));
    echo $OUTPUT->footer();
    die;
}

// Initialize variables
$hoi_filter_b = '';
$hoi_filter_f = '';
$enrollmentData = [];
$courseNames = [];
$totalStudentsData = [];
$enrolledStudentsData = [];
$unenrolledStudentsData = [];
$enrollmentPercentages = [];
$facultyID = '';

// Apply HOI institute filter - HOI can only see their own institutes
if ($USER->usertype == 'HOI') {
    $hoi_filter_b = " AND b.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode') ";
    $hoi_filter_f = " AND f.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode') ";
}

// Build WHERE conditions
$whereConditions = "WHERE TRIM(REPLACE(b.NAME, '\r', '')) = '" . $DB->sql_like_escape($facultyName) . "'";

if ($selectedCity) {
    $whereConditions .= " AND b.CITY = '" . $DB->sql_like_escape($selectedCity) . "'";
}

if ($USER->usertype == 'HOI') {
    $whereConditions .= $hoi_filter_b;
}

// Verify if faculty exists and user has permission to view
$facultyCheck = $DB->get_record_sql("
    SELECT b.ID, b.NAME, b.CITY 
    FROM my_faculty b
    $whereConditions
    LIMIT 1
");

if (!$facultyCheck) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Faculty not found or you do not have permission to view this faculty', 'error');
    echo '<div style="text-align: center; margin: 20px;">
            <a href="' . $CFG->wwwroot . '/local/amizone/reportchartdashboard.php' . ($selectedCity ? '?city=' . urlencode($selectedCity) : '') . '" class="btn btn-primary">
                <i class="fa fa-arrow-left"></i> Back to Dashboard
            </a>
          </div>';
    echo $OUTPUT->footer();
    die;
}

// ============================================================================
// FIXED: Use same logic as dashboard - COUNT DISTINCT students per section
// This matches the dashboard's student counting methodology
// ============================================================================

$sql = "
SELECT 
    a.ID as course_id,
    a.TITLE as course_name,
    b.NAME as faculty_name,
    b.username as faculty_id,
    b.CITY as campus,
    COALESCE(student_counts.total_students, 0) as total_students,
    COALESCE(student_counts.enrolled_students, 0) as enrolled_students,
    COALESCE(student_counts.total_students, 0) - COALESCE(student_counts.enrolled_students, 0) as unenrolled_students
FROM my_moodle_course a
JOIN my_faculty b ON a.FACULTY = b.ID
LEFT JOIN (
    SELECT
        mss.SECTION,
        COUNT(DISTINCT mss.STUDENT) as total_students,
        COUNT(DISTINCT CASE WHEN ra.userid IS NOT NULL THEN mss.STUDENT END) as enrolled_students
    FROM my_section_students mss
    LEFT JOIN my_student ms ON mss.STUDENT = ms.ID
    LEFT JOIN my_moodle_course mmc ON mss.SECTION = mmc.ID
    LEFT JOIN mdl_context ctx ON mmc.MOODLE_COURSE = ctx.instanceid AND ctx.contextlevel = 50
    LEFT JOIN mdl_role_assignments ra ON ctx.id = ra.contextid
                                       AND ra.roleid = 5
                                       AND ra.userid = ms.MOODLESTUDENTID
    GROUP BY mss.SECTION
) student_counts ON a.ID = student_counts.SECTION
$whereConditions
ORDER BY a.TITLE
";

$result = $DB->get_records_sql($sql);

// Process the data
foreach ($result as $row) {
    // Set faculty ID from the first record found
    if (empty($facultyID)) {
        $facultyID = $row->faculty_id;
    }
    
    $courseName = $row->course_name;
    $courseId = $row->course_id;
    $totalStudents = (int)$row->total_students;
    $enrolledStudents = (int)$row->enrolled_students;
    $unenrolledStudents = (int)$row->unenrolled_students;
    $enrollmentPercentage = $totalStudents > 0 ? round(($enrolledStudents / $totalStudents) * 100, 0) : 0;
    
    $courseNames[] = $courseName;
    $totalStudentsData[] = $totalStudents;
    $enrolledStudentsData[] = $enrolledStudents;
    $unenrolledStudentsData[] = $unenrolledStudents;
    $enrollmentPercentages[] = $enrollmentPercentage;
    
    $enrollmentData[] = [
        'course_id' => $courseId,
        'course_name' => $courseName,
        'campus' => $row->campus,
        'faculty_id' => $row->faculty_id,
        'total_students' => $totalStudents,
        'enrolled_students' => $enrolledStudents,
        'unenrolled_students' => $unenrolledStudents,
        'enrollment_percentage' => $enrollmentPercentage
    ];
}

// ============================================================================
// CALCULATE SUMMARY STATISTICS - MATCH DASHBOARD METHODOLOGY
// Use DISTINCT count across ALL courses under this faculty (not sum of courses)
// ============================================================================

$cardFilterConditions = "WHERE TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($facultyName) . "'";

if ($selectedCity) {
    $cardFilterConditions .= " AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'";
}

if ($USER->usertype == 'HOI') {
    $cardFilterConditions .= $hoi_filter_f;
}

// Total Students (Registered) - Count DISTINCT students across all courses
$registeredStudentSql = "
    SELECT COUNT(mss.STUDENT)
    FROM my_section_students mss
    JOIN my_moodle_course mmc ON mss.SECTION = mmc.ID
    JOIN my_faculty f ON mmc.FACULTY = f.ID
    $cardFilterConditions
";
$totalStudentsSum = $DB->count_records_sql($registeredStudentSql);

// Total Students (Enrolled) - Count DISTINCT enrolled students across all courses
$enrolledConditions = "WHERE TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($facultyName) . "'";

if ($selectedCity) {
    $enrolledConditions .= " AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'";
}

if ($USER->usertype == 'HOI') {
    $enrolledConditions .= $hoi_filter_f;
}

$totalEnrolledSql = "
    SELECT COUNT(DISTINCT ra.userid) AS enrolled_students
    FROM my_section_students mss
    JOIN my_student ms ON mss.STUDENT = ms.ID
    JOIN my_moodle_course mmc ON mss.SECTION = mmc.ID
    JOIN my_faculty f ON mmc.FACULTY = f.ID
    JOIN mdl_context ctx ON mmc.MOODLE_COURSE = ctx.instanceid AND ctx.contextlevel = 50
    JOIN mdl_role_assignments ra ON ctx.id = ra.contextid
                                       AND ra.roleid = 5
                                       AND ra.userid = ms.MOODLESTUDENTID
    $enrolledConditions
";
$enrolledStudentsSum = $DB->count_records_sql($totalEnrolledSql);

// Calculate unenrolled and percentage
$unenrolledStudentsSum = $totalStudentsSum - $enrolledStudentsSum;
$overallEnrollmentPercentage = $totalStudentsSum > 0 ? round(($enrolledStudentsSum / $totalStudentsSum) * 100, 2) : 0;

// Convert enrollment data to JSON for JavaScript download
$enrollmentDataJson = json_encode($enrollmentData);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Enrollment Details - <?php echo htmlspecialchars($facultyName); ?></title>
    <?php echo $OUTPUT->standard_head_html() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }

        .dashboard-header {
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }

        .dashboard-header h1 {
            margin: 0;
            font-size: 28px;
        }

        .dashboard-header .subtitle {
            margin-top: 5px;
            font-size: 16px;
            opacity: 0.9;
        }
        
        .dashboard-header .subtitle.faculty-info {
            font-size: 18px;
            font-weight: bold;
        }

        .container {
            width: 95%;
            margin: 0 auto;
            padding-bottom: 30px;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background-color: #007BFF;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }

        .back-button:hover {
            background-color: #0056b3;
            color: white;
            text-decoration: none;
        }

        .download-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .download-btn:hover {
            background: linear-gradient(45deg, #218838, #1ea080);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            color: white;
            text-decoration: none;
        }

        .dropdown-menu {
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .dropdown-item:hover {
            background-color: #f8f9fa;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
        }

        .summary-card h3 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
        }

        .summary-card .value {
            font-size: 36px;
            font-weight: bold;
            color: #2c3e50;
        }

        .summary-card.total .value {
            color: #007BFF;
        }

        .summary-card.enrolled .value {
            color: #28a745;
        }

        .summary-card.unenrolled .value {
            color: #dc3545;
        }

        .summary-card.percentage .value {
            color: #17a2b8;
        }

        .chart-container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .chart-container h2 {
            margin-top: 0;
            color: #2c3e50;
            text-align: center;
        }

        .chart-wrapper {
            height: 500px;
            position: relative;
        }

        .horizontal-chart-wrapper {
            height: auto;
            min-height: 400px;
            position: relative;
        }

        .data-table {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 20px;
            overflow-x: auto;
        }

        .data-table h2 {
            margin-top: 0;
            color: #2c3e50;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th {
            background-color: #2c3e50;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }

        table tr:hover {
            background-color: #f5f5f5;
        }

        .percentage-bar {
            height: 20px;
            background-color: #dc3545;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .percentage-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            transition: width 0.3s ease;
        }

        .percentage-text {
            position: absolute;
            width: 100%;
            text-align: center;
            line-height: 20px;
            font-weight: bold;
            font-size: 12px;
            color: #2c3e50;
        }
        
        .wrapped-label {
            white-space: normal !important;
            word-wrap: break-word !important;
            word-break: break-word !important;
            max-width: 400px !important;
            width: 400px !important;
        }

        .download-success {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1050;
        }

        .alert-success {
            border-left: 4px solid #28a745;
        }

        .clickable-number {
            color: #007BFF;
            cursor: pointer;
            text-decoration: underline;
            font-weight: bold;
        }

        .clickable-number:hover {
            color: #0056b3;
            text-decoration: none;
        }
    </style>
</head>
<body>

<?php echo $OUTPUT->header(); ?>

<div class="dashboard-header">
    <h1>Enrollment Details</h1>
    <div class="subtitle faculty-info">
        Faculty: <?php echo htmlspecialchars($facultyName); ?> 
        <?php if (!empty($facultyID)): ?>
        (ID: <?php echo htmlspecialchars($facultyID); ?>)
        <?php endif; ?>
    </div>
    <?php if ($selectedCity): ?>
    <div class="subtitle">Campus: <?php echo htmlspecialchars($selectedCity); ?></div>
    <?php endif; ?>
</div>

<div class="container">
    <div class="top-bar">
        <a href="<?php echo $CFG->wwwroot; ?>/local/amizone/reportchartdashboard.php<?php echo $selectedCity ? '?city=' . urlencode($selectedCity) : ''; ?><?php echo $facultyName ? ($selectedCity ? '&' : '?') . 'faculty=' . urlencode($facultyName) : ''; ?>" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <?php if (!empty($enrollmentData)): ?>
        <div class="dropdown">
            <button class="download-btn dropdown-toggle" type="button" id="downloadDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fa fa-download"></i> Download Report
            </button>
            <ul class="dropdown-menu" aria-labelledby="downloadDropdown">
                <li><a class="dropdown-item" href="#" onclick="downloadReport('csv')">
                    <i class="fa fa-file-text"></i> Download as CSV
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="downloadReport('excel')">
                    <i class="fa fa-file-excel"></i> Download as Excel
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="downloadReport('json')">
                    <i class="fa fa-code"></i> Download as JSON
                </a></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <div id="downloadSuccessMessage" class="alert alert-success download-success" role="alert" style="display: none;">
        <i class="fa fa-check-circle"></i> Download complete! Saved as: <strong id="downloadFilename"></strong>
    </div>

    <?php if (!empty($enrollmentData)): ?>
    
    <div class="summary-cards">
        <div class="summary-card total">
            <div class="card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="card-content">
                <h3>Total Students</h3>
                <div class="value stat-total"><?php echo $totalStudentsSum; ?></div>
            </div>
        </div>
        <div class="summary-card enrolled">
            <div class="card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
            </div>
            <div class="card-content">
                <h3>Enrolled Students</h3>
                <div class="value stat-enrolled"><?php echo $enrolledStudentsSum; ?></div>
            </div>
        </div>
        <div class="summary-card unenrolled">
            <div class="card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
            </div>
            <div class="card-content">
                <h3>Unenrolled Students</h3>
                <div class="value stat-unenrolled"><?php echo $unenrolledStudentsSum; ?></div>
            </div>
        </div>
        <div class="summary-card percentage">
            <div class="card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 17 13.5 8.5 8.5 13.5 2 7"/><polyline points="16 17 22 17 22 11"/></svg>
            </div>
            <div class="card-content">
                <h3>Enrollment Rate</h3>
                <div class="value"><?php echo $overallEnrollmentPercentage; ?>%</div>
            </div>
        </div>
    </div>

    <div class="chart-container">
        <h2>Course-wise Enrollment Comparison</h2>
        <div class="horizontal-chart-wrapper" style="height: <?php echo max(400, count($courseNames) * 60); ?>px;">
            <canvas id="enrollmentChart"></canvas>
        </div>
    </div>

    <div class="chart-container">
        <h2>Overall Enrollment Distribution</h2>
        <div class="chart-wrapper" style="height: 400px;">
            <canvas id="pieChart"></canvas>
        </div>
    </div>

    <div class="data-table">
        <h2>Detailed Enrollment Data</h2>
        <table>
            <thead>
                <tr>
                    <th>Course Name</th>
                    <th>Campus</th>
                    <th>Total Students</th>
                    <th>Enrolled Students</th>
                    <th>Unenrolled Students</th>
                    <th>Enrollment Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enrollmentData as $data): ?>
                <tr>
                    <td class="wrapped-label"><?php echo htmlspecialchars($data['course_name']); ?></td>
                    <td><?php echo htmlspecialchars($data['campus']); ?></td>
                    <td>
                        <a href="<?php echo $CFG->wwwroot; ?>/local/amizone/userenrollment.php?course_id=<?php echo $data['course_id']; ?>&type=total" 
                           class="clickable-number" target="_blank">
                            <?php echo $data['total_students']; ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?php echo $CFG->wwwroot; ?>/local/amizone/userenrollment.php?course_id=<?php echo $data['course_id']; ?>&type=enrolled" 
                           class="clickable-number" style="color: #28a745;" target="_blank">
                            <?php echo $data['enrolled_students']; ?>
                        </a>
                    </td>
                    <td>
                        <a href="<?php echo $CFG->wwwroot; ?>/local/amizone/userenrollment.php?course_id=<?php echo $data['course_id']; ?>&type=unenrolled" 
                           class="clickable-number" style="color: #dc3545;" target="_blank">
                            <?php echo $data['unenrolled_students']; ?>
                        </a>
                    </td>
                    <td>
                        <div class="percentage-bar">
                            <div class="percentage-fill" style="width: <?php echo $data['enrollment_percentage']; ?>%"></div>
                            <div class="percentage-text"><?php echo $data['enrollment_percentage']; ?>%</div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="alert alert-warning text-center" role="alert">
        No enrollment data available for this faculty.
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
<?php if (!empty($enrollmentData)): ?>

const enrollmentData = <?php echo $enrollmentDataJson; ?>;
const facultyName = <?php echo json_encode($facultyName); ?>;
const facultyID = <?php echo json_encode($facultyID); ?>;
const selectedCity = <?php echo json_encode($selectedCity); ?>;
const summaryStats = {
    total_students: <?php echo $totalStudentsSum; ?>,
    enrolled_students: <?php echo $enrolledStudentsSum; ?>,
    unenrolled_students: <?php echo $unenrolledStudentsSum; ?>,
    overall_enrollment_percentage: <?php echo $overallEnrollmentPercentage; ?>
};

function showDownloadSuccess(format, filename) {
    const messageBox = document.getElementById('downloadSuccessMessage');
    const filenameSpan = document.getElementById('downloadFilename');
    
    filenameSpan.textContent = filename;
    messageBox.style.display = 'block';
    
    setTimeout(() => {
        messageBox.style.display = 'none';
    }, 5000);
}

function downloadReport(format = 'csv') {
    const now = new Date();
    const timestamp = now.getFullYear() + '-' + 
                     String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                     String(now.getDate()).padStart(2, '0') + '_' + 
                     String(now.getHours()).padStart(2, '0') + '-' + 
                     String(now.getMinutes()).padStart(2, '0');
    
    const facultyPart = facultyName.replace(/[^a-zA-Z0-9]/g, '_');
    const cityPart = selectedCity ? '_' + selectedCity : '';
    
    let content, mimeType, filename;

    switch(format) {
        case 'csv':
        case 'excel':
            content = generateCSV();
            content = (format === 'excel' ? "\uFEFF" : "") + content;
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Enrollment_Report_${facultyPart}${cityPart}_${timestamp}.csv`;
            break;
        case 'json':
            content = JSON.stringify({
                report_title: `Enrollment Report - ${facultyName}`,
                faculty: facultyName,
                faculty_id: facultyID,
                campus: selectedCity || 'All Campuses',
                generated_at: now.toISOString(),
                summary: summaryStats,
                enrollment_data: enrollmentData
            }, null, 2);
            mimeType = 'application/json;charset=utf-8;';
            filename = `Enrollment_Report_${facultyPart}${cityPart}_${timestamp}.json`;
            break;
        default:
            content = generateCSV();
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Enrollment_Report_${facultyPart}${cityPart}_${timestamp}.csv`;
    }

    const blob = new Blob([content], { type: mimeType });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    showDownloadSuccess(format, filename);
}

function generateCSV() {
    let csvContent = "Faculty Enrollment Report\n";
    csvContent += `Faculty Name: ${facultyName}\n`;
    csvContent += `Faculty ID: ${facultyID}\n`;
    csvContent += `Campus: ${selectedCity || 'All Campuses'}\n`;
    csvContent += `Generated: ${new Date().toLocaleString()}\n\n`;
    
    csvContent += "Summary Statistics\n";
    csvContent += `Total Students,${summaryStats.total_students}\n`;
    csvContent += `Enrolled Students,${summaryStats.enrolled_students}\n`;
    csvContent += `Unenrolled Students,${summaryStats.unenrolled_students}\n`;
    csvContent += `Overall Enrollment Rate,${summaryStats.overall_enrollment_percentage}%\n\n`;
    
    csvContent += "Detailed Course Data\n";
    csvContent += "Course Name,Campus,Faculty ID,Total Students,Enrolled Students,Unenrolled Students,Enrollment Rate (%)\n";

    enrollmentData.forEach(row => {
        const courseName = JSON.stringify(row.course_name.replace(/"/g, '""'));
        const campus = JSON.stringify(row.campus.replace(/"/g, '""'));
        const facultyId = JSON.stringify(row.faculty_id.replace(/"/g, '""'));
        csvContent += `${courseName},${campus},${facultyId},${row.total_students},${row.enrolled_students},${row.unenrolled_students},${row.enrollment_percentage}\n`;
    });

    return csvContent;
}

Chart.register(ChartDataLabels);

const courseNames = <?php echo json_encode($courseNames); ?>;
const totalStudentsData = <?php echo json_encode($totalStudentsData); ?>;
const enrolledStudentsData = <?php echo json_encode($enrolledStudentsData); ?>;
const unenrolledStudentsData = <?php echo json_encode($unenrolledStudentsData); ?>;
const enrollmentPercentages = <?php echo json_encode($enrollmentPercentages); ?>;

function wrapLabel(context, maxWidth = 300) {
    const words = context.split(' ');
    const lines = [];
    let currentLine = words[0];

    for (let i = 1; i < words.length; i++) {
        const word = words[i];
        const width = currentLine.length + word.length;
        if (width < 40) {
            currentLine += ' ' + word;
        } else {
            lines.push(currentLine);
            currentLine = word;
        }
    }
    lines.push(currentLine);
    return lines;
}

const ctx = document.getElementById('enrollmentChart').getContext('2d');
const enrollmentChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: courseNames,
        datasets: [
            {
                label: 'Total Students',
                data: totalStudentsData,
                backgroundColor: '#28a745',
                borderColor: '#28a745',
                borderWidth: .5
            },
            {
                label: 'Enrolled Students',
                data: enrolledStudentsData,
                backgroundColor: 'rgba(54, 162, 235, 0.85)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1,
                barThickness: 10
            },
            {
                label: 'Unenrolled Students',
                data: unenrolledStudentsData,
                backgroundColor: '#dc3545',
                borderColor: '#dc3545',
                borderWidth: 1,
                barThickness: 10
            }
        ]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    font: {
                        size: 13,
                        weight: 'bold'
                    }
                }
            },
            tooltip: {
                callbacks: {
                    title: function(context) {
                        return 'Course: ' + context[0].label;
                    },
                    label: function(context) {
                        const dataIndex = context.dataIndex;
                        const total = totalStudentsData[dataIndex];
                        const value = context.parsed.x;
                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                        return context.dataset.label + ': ' + value + ' (' + percentage + '%)';
                    },
                    afterBody: function(context) {
                        const dataIndex = context[0].dataIndex;
                        const total = totalStudentsData[dataIndex];
                        const enrollmentRate = enrollmentPercentages[dataIndex];
                        return '\nTotal Students: ' + total + '\nEnrollment Rate: ' + enrollmentRate + '%';
                    }
                }
            },
            datalabels: {
                color: '#fff',
                anchor: 'center',
                align: 'center',
                font: { weight: 'bold', size: 11 },
                formatter: (value) => value > 0 ? value : '',
                display: context => context.dataset.data[context.dataIndex] > 0
            }
        },
        scales: {
            x: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Number of Students',
                    font: { size: 14, weight: 'bold' }
                },
                ticks: {
                    precision: 0,
                    font: { size: 12 }
                }
            },
            y: {
                title: {
                    display: true,
                    text: 'Courses',
                    font: { size: 14, weight: 'bold' }
                },
                ticks: {
                    autoSkip: false,
                    font: { size: 11 },
                    callback: function(value, index, values) {
                        const label = this.getLabelForValue(value);
                        const wrappedLines = wrapLabel(label);
                        return wrappedLines;
                    }
                },
                afterFit: function(scale) {
                    scale.width = 300;
                }
            }
        }
    },
    plugins: [{
        afterDatasetsDraw: function(chart) {
            const ctx = chart.ctx;
            const meta = chart.getDatasetMeta(2);
            
            if (!meta.hidden) {
                meta.data.forEach((bar, index) => {
                    const percentage = enrollmentPercentages[index];
                    
                    ctx.save();
                    ctx.fillStyle = '#000';
                    ctx.font = 'bold 12px Arial';
                    ctx.textAlign = 'left';
                    ctx.textBaseline = 'middle';
                    
                    const totalBar = chart.getDatasetMeta(0).data[index];
                    const enrolledBar = chart.getDatasetMeta(1).data[index];
                    const unenrolledBar = chart.getDatasetMeta(2).data[index];
                    
                    const maxX = Math.max(totalBar.x, enrolledBar.x, unenrolledBar.x);
                    const x = maxX + 8;
                    const y = bar.y;
                    
                    ctx.fillText(percentage + '%', x, y);
                    ctx.restore();
                });
            }
        }
    }]
});

const ctxPie = document.getElementById('pieChart').getContext('2d');
const pieChart = new Chart(ctxPie, {
    type: 'pie',
    data: {
        labels: ['Enrolled Students', 'Unenrolled Students'],
        datasets: [{
            data: [<?php echo $enrolledStudentsSum; ?>, <?php echo $unenrolledStudentsSum; ?>],
            backgroundColor: [
                '#399be2',
                '#dc3545'
            ],
            borderColor: [
                '#28a745',
                '#dc3545'
            ],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'bottom',
                labels: {
                    font: { size: 13 }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = <?php echo $totalStudentsSum; ?>;
                        const value = context.parsed;
                        const percentage = total > 0 ? ((value / total) * 100).toFixed(2) : 0;
                        return context.label + ': ' + value + ' (' + percentage + '%)';
                    }
                }
            },
            datalabels: {
                color: '#fff',
                font: { weight: 'bold', size: 14 },
                formatter: (value, context) => {
                    const total = <?php echo $totalStudentsSum; ?>;
                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                    return value + '\n(' + percentage + '%)';
                }
            }
        }
    }
});
<?php endif; ?>
</script>

<?php echo $OUTPUT->footer(); ?>

</body>
</html>
