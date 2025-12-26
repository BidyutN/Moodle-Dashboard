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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Student Enrollment List
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
$courseId = required_param('course_id', PARAM_INT);
$type = required_param('type', PARAM_TEXT); // 'total', 'enrolled', or 'unenrolled'

// Set up page
$PAGE->set_url(new moodle_url("/local/amizone/userenrollment.php", ['course_id' => $courseId, 'type' => $type]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Student Enrollment List");
$PAGE->set_heading("Student Enrollment List");

// Initialize variables to prevent null errors
$students = [];
$totalCount = 0;
$enrolledCount = 0;
$unenrolledCount = 0;
$studentsArray = [];
$courseDetails = null;
$pageTitle = "Student Enrollment List";
$studentsJson = '[]';
$courseDetailsJson = '{}';

// Check permissions - Allow HOI, Admin, and Faculty users
if (is_siteadmin() || $USER->usertype == 'HOI' || $USER->usertype == 'Faculty') {

    // Get course details
    $courseDetails = $DB->get_record_sql("
    SELECT 
        mmc.ID as course_id,
        mmc.TITLE as course_name,
        mf.NAME as faculty_name,
        mf.CITY as campus
    FROM my_moodle_course mmc
    JOIN my_faculty mf ON mmc.FACULTY = mf.ID
    WHERE mmc.ID = ?
", [$courseId]);

    if (!$courseDetails) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification('Course not found', 'error');
        echo $OUTPUT->footer();
        die;
    }

    // For Faculty users, verify they have access to this course
    if ($USER->usertype == 'Faculty') {
        $facultyCourseCheck = $DB->get_record_sql("
            SELECT COUNT(*) as has_access 
            FROM my_moodle_course mmc
            JOIN my_faculty mf ON mmc.FACULTY = mf.ID
            WHERE mmc.ID = ? AND mf.ID = ?
        ", [$courseId, $USER->idnumber]);
        
        if (!$facultyCourseCheck || $facultyCourseCheck->has_access == 0) {
            echo $OUTPUT->header();
            echo $OUTPUT->notification('You do not have permission to view this course', 'error');
            echo $OUTPUT->footer();
            die;
        }
    }

    // Build the query with email and enrollment number
    $sql = "
    SELECT 
        CONCAT(mss.SECTION, '-', ms.ID) as unique_id,
        ms.ID as student_id,
        ms.NAME as student_name,
        ms.email as email,
        ms.username as enrollment_number,
        CASE 
            WHEN ra.userid IS NOT NULL THEN 'Enrolled'
            ELSE 'Unenrolled'
        END as enrollment_status
    FROM my_section_students mss
    JOIN my_student ms ON mss.STUDENT = ms.ID
    JOIN my_moodle_course mmc ON mss.SECTION = mmc.ID
    LEFT JOIN mdl_context ctx ON mmc.MOODLE_COURSE = ctx.instanceid AND ctx.contextlevel = 50
    LEFT JOIN mdl_role_assignments ra ON ctx.id = ra.contextid
                                       AND ra.roleid = 5
                                       AND ra.userid = ms.MOODLESTUDENTID
    WHERE mss.SECTION = ?
    ";

    // Add filter based on type
    if ($type === 'enrolled') {
        $sql .= " AND ra.userid IS NOT NULL";
        $pageTitle = "Enrolled Students";
    } elseif ($type === 'unenrolled') {
        $sql .= " AND ra.userid IS NULL";
        $pageTitle = "Unenrolled Students";
    } else {
        $pageTitle = "All Students";
    }

    $sql .= " ORDER BY enrollment_status DESC, ms.NAME";

    $students = $DB->get_records_sql($sql, [$courseId]);

    // Count statistics
    $totalCount = 0;
    $enrolledCount = 0;
    $unenrolledCount = 0;
    $studentsArray = [];

    foreach ($students as $student) {
        $totalCount++;
        if ($student->enrollment_status === 'Enrolled') {
            $enrolledCount++;
        } else {
            $unenrolledCount++;
        }
        
        $studentsArray[] = [
            'student_id' => $student->student_id,
            'enrollment_number' => $student->enrollment_number,
            'student_name' => $student->student_name,
            'email' => $student->email,
            'enrollment_status' => $student->enrollment_status
        ];
    }

    $studentsJson = json_encode($studentsArray);
    $courseDetailsJson = json_encode([
        'course_id' => $courseDetails->course_id,
        'course_name' => $courseDetails->course_name,
        'faculty_name' => $courseDetails->faculty_name,
        'campus' => $courseDetails->campus
    ]);

} else {
    // User doesn't have permission
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermissions', 'error', 'access this page'));
    echo $OUTPUT->footer();
    die;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($courseDetails->course_name); ?></title>
    <?php echo $OUTPUT->standard_head_html() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #007bff;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --dark-color: #343a40;
            --light-bg: #f8f9fa;
            --card-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--light-bg);
        }

        .dashboard-header {
            background-color: var(--dark-color);
            color: white;
            padding: 30px 20px;
            text-align: center;
            margin-bottom: 30px;
        }

        .dashboard-header h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 700;
        }

        .dashboard-header .subtitle {
            margin-top: 5px;
            font-size: 18px;
            opacity: 0.9;
            font-weight: 300;
        }
        
        .course-context-in-header {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 14px;
            line-height: 1.6;
            text-align: left;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .course-context-in-header p {
            margin: 0;
            padding: 3px 0;
        }

        .course-context-in-header strong {
            font-weight: 600;
            color: #ccc;
            min-width: 80px;
            display: inline-block;
        }

        .container {
            width: 95%;
            max-width: 1400px;
            margin: 0 auto;
            padding-bottom: 40px;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            background-color: var(--secondary-color);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            transition: background-color 0.3s, transform 0.2s;
        }

        .back-button i {
            margin-right: 8px;
        }

        .back-button:hover {
            background-color: #5a6268;
            color: white;
            transform: translateY(-2px);
            text-decoration: none;
        }

        .download-btn {
            background: linear-gradient(45deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .download-btn i {
            margin-right: 8px;
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

        .dropdown-item {
            padding: 10px 20px;
            display: flex;
            align-items: center;
        }

        .dropdown-item i {
            margin-right: 10px;
            width: 20px;
        }

        .dropdown-item:hover {
            background-color: #f8f9fa;
        }

        .download-success {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1050;
            min-width: 300px;
        }

        .alert-success {
            border-left: 4px solid #28a745;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .summary-card {
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            padding: 25px;
            text-align: center;
            border-left: 5px solid transparent;
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            box-shadow: 0 6px 12px rgba(0,0,0,0.1);
            transform: translateY(-3px);
        }

        .summary-card h3 {
            margin: 0 0 10px 0;
            color: var(--secondary-color);
            font-size: 13px;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .summary-card .value {
            font-size: 42px;
            font-weight: 700;
            color: var(--dark-color);
        }

        .summary-card.total { border-left-color: var(--primary-color); }
        .summary-card.total .value { color: var(--primary-color); }

        .summary-card.enrolled { border-left-color: var(--success-color); }
        .summary-card.enrolled .value { color: var(--success-color); }

        .summary-card.unenrolled { border-left-color: var(--danger-color); }
        .summary-card.unenrolled .value { color: var(--danger-color); }

        .data-table {
            background-color: white;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            padding: 25px;
            overflow-x: auto;
        }

        .data-table h2 {
            margin-top: 0;
            color: var(--dark-color);
            font-size: 22px;
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        table th {
            background-color: var(--dark-color);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
        }

        table thead tr:first-child th:first-child { border-top-left-radius: 8px; }
        table thead tr:first-child th:last-child { border-top-right-radius: 8px; }
        
        table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
            font-size: 15px;
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        table tbody tr:nth-child(even) {
            background-color: #fcfcfc;
        }

        table tr:hover {
            background-color: #f5f5f5;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-enrolled {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }

        .status-unenrolled {
            background-color: #f8d7da;
            color: var(--danger-color);
            border: 1px solid #f5c6cb;
        }

        .status-icon {
            margin-right: 6px;
            font-size: 14px;
        }

        .filter-buttons {
            margin-bottom: 25px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 10px 20px;
            border: 1px solid var(--primary-color);
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .filter-btn.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }

        .filter-btn:not(.active) {
            background-color: white;
            color: var(--primary-color);
        }

        .filter-btn:not(.active):hover {
            background-color: #f0f8ff;
            text-decoration: none;
        }

        .alert.alert-info {
            border-radius: 8px;
            padding: 20px;
        }

        .wrapped-label {
            white-space: normal !important;
            word-wrap: break-word !important;
            word-break: break-word !important;
        }
    </style>
</head>
<body>

<?php echo $OUTPUT->header(); ?>

<div class="dashboard-header">
    <h1><i class="fas fa-users-class me-2"></i> <?php echo htmlspecialchars($pageTitle); ?></h1>
    
    <div class="course-context-in-header">
        <p><strong>Course Name:</strong> <?php echo htmlspecialchars($courseDetails->course_name); ?></p>
        <p><strong>Faculty:</strong> <?php echo htmlspecialchars($courseDetails->faculty_name); ?></p>
        <p><strong>Campus:</strong> <?php echo htmlspecialchars($courseDetails->campus); ?></p>
    </div>
</div>

<div class="container">
    <div class="top-bar">
        <a href="javascript:history.back()" class="back-button">
            <i class="fas fa-arrow-left"></i> Go Back
        </a>

        <?php if (!empty($students)): ?>
        <div class="dropdown">
            <button class="download-btn dropdown-toggle" type="button" id="downloadDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fa fa-download"></i> Download Report
            </button>
            <ul class="dropdown-menu" aria-labelledby="downloadDropdown">
                <li><a class="dropdown-item" href="#" onclick="downloadReport('csv'); return false;">
                    <i class="fa fa-file-csv"></i> Download as CSV
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="downloadReport('excel'); return false;">
                    <i class="fa fa-file-excel"></i> Download as Excel
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="downloadReport('json'); return false;">
                    <i class="fa fa-code"></i> Download as JSON
                </a></li>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <div id="downloadSuccessMessage" class="alert alert-success download-success" role="alert" style="display: none;">
        <i class="fa fa-check-circle"></i> Download complete! Saved as: <strong id="downloadFilename"></strong>
    </div>

    <div class="summary-cards">
        <div class="summary-card total">
            <h3><i class="fas fa-list-ol"></i> Total Students</h3>
            <div class="value"><?php echo $totalCount; ?></div>
        </div>
        <div class="summary-card enrolled">
            <h3><i class="fas fa-check-double"></i> Enrolled</h3>
            <div class="value"><?php echo $enrolledCount; ?></div>
        </div>
        <div class="summary-card unenrolled">
            <h3><i class="fas fa-user-times"></i> Unenrolled</h3>
            <div class="value"><?php echo $unenrolledCount; ?></div>
        </div>
    </div>

    <div class="filter-buttons">
        <a href="<?php echo $CFG->wwwroot; ?>/local/amizone/userenrollment.php?course_id=<?php echo $courseId; ?>&type=total" 
           class="filter-btn <?php echo $type === 'total' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> All Students (<?php echo $totalCount; ?>)
        </a>
        <a href="<?php echo $CFG->wwwroot; ?>/local/amizone/userenrollment.php?course_id=<?php echo $courseId; ?>&type=enrolled" 
           class="filter-btn <?php echo $type === 'enrolled' ? 'active' : ''; ?>">
            <i class="fas fa-user-check"></i> Enrolled Only (<?php echo $enrolledCount; ?>)
        </a>
        <a href="<?php echo $CFG->wwwroot; ?>/local/amizone/userenrollment.php?course_id=<?php echo $courseId; ?>&type=unenrolled" 
           class="filter-btn <?php echo $type === 'unenrolled' ? 'active' : ''; ?>">
            <i class="fas fa-user-slash"></i> Unenrolled Only (<?php echo $unenrolledCount; ?>)
        </a>
    </div>

    <div class="data-table">
        <h2><i class="fas fa-table me-2"></i> Student List (<?php echo count($students); ?>)</h2>
        <?php if (!empty($students)): ?>
        <table class="table">
            <thead>
                <tr>
                    <th>S.No.</th>
                    <th>Enrollment Number</th>
                    <th>Student Name</th>
                    <th>Email</th>
                    <th>Enrollment Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($students as $student): 
                ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><i class="fas fa-id-card-alt me-1"></i> <?php echo htmlspecialchars($student->enrollment_number); ?></td>
                    <td class="wrapped-label"><?php echo htmlspecialchars($student->student_name); ?></td>
                    <td><a href="mailto:<?php echo htmlspecialchars($student->email); ?>"><?php echo htmlspecialchars($student->email); ?></a></td>
                    <td>
                        <?php if ($student->enrollment_status === 'Enrolled'): ?>
                            <span class="status-badge status-enrolled">
                                <i class="fas fa-check-circle status-icon"></i>
                                Enrolled
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-unenrolled">
                                <i class="fas fa-times-circle status-icon"></i>
                                Unenrolled
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="alert alert-info text-center" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> No students found for this filter.
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
<?php if (!empty($students)): ?>

const studentsData = <?php echo $studentsJson; ?>;
const courseDetails = <?php echo $courseDetailsJson; ?>;
const pageTitle = <?php echo json_encode($pageTitle); ?>;
const filterType = <?php echo json_encode($type); ?>;
const summaryStats = {
    total_students: <?php echo $totalCount; ?>,
    enrolled_students: <?php echo $enrolledCount; ?>,
    unenrolled_students: <?php echo $unenrolledCount; ?>
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
    
    const courseNamePart = courseDetails.course_name.replace(/[^a-zA-Z0-9]/g, '_').substring(0, 50);
    const typePart = filterType.charAt(0).toUpperCase() + filterType.slice(1);
    
    let content, mimeType, filename;

    switch(format) {
        case 'csv':
            content = generateCSV();
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Student_List_${typePart}_${courseNamePart}_${timestamp}.csv`;
            break;
        case 'excel':
            content = "\uFEFF" + generateCSV();
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Student_List_${typePart}_${courseNamePart}_${timestamp}.csv`;
            break;
        case 'json':
            content = JSON.stringify({
                report_title: `Student Enrollment Report - ${pageTitle}`,
                course_details: courseDetails,
                filter_type: filterType,
                generated_at: now.toISOString(),
                summary: summaryStats,
                students: studentsData
            }, null, 2);
            mimeType = 'application/json;charset=utf-8;';
            filename = `Student_List_${typePart}_${courseNamePart}_${timestamp}.json`;
            break;
        default:
            content = generateCSV();
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Student_List_${typePart}_${courseNamePart}_${timestamp}.csv`;
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
    let csvContent = "Student Enrollment Report\n";
    csvContent += `Report Type: ${pageTitle}\n`;
    csvContent += `Course: ${courseDetails.course_name}\n`;
    csvContent += `Faculty: ${courseDetails.faculty_name}\n`;
    csvContent += `Campus: ${courseDetails.campus}\n`;
    csvContent += `Generated: ${new Date().toLocaleString()}\n\n`;
    
    csvContent += "Summary Statistics\n";
    csvContent += `Total Students,${summaryStats.total_students}\n`;
    csvContent += `Enrolled Students,${summaryStats.enrolled_students}\n`;
    csvContent += `Unenrolled Students,${summaryStats.unenrolled_students}\n\n`;
    
    csvContent += "Student Details\n";
    csvContent += "S.No.,Enrollment Number,Student Name,Email,Enrollment Status\n";

    studentsData.forEach((student, index) => {
        const enrollmentNumber = JSON.stringify(student.enrollment_number || '');
        const studentName = JSON.stringify(student.student_name.replace(/"/g, '""'));
        const email = JSON.stringify(student.email || '');
        const status = student.enrollment_status;
        csvContent += `${index + 1},${enrollmentNumber},${studentName},${email},${status}\n`;
    });

    return csvContent;
}

<?php endif; ?>
</script>

<?php echo $OUTPUT->footer(); ?>

</body>
</html>
