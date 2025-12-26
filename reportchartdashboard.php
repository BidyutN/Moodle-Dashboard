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
 * Dashboard Report for Moodle LMS with Faculty Filter
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
$selectedCity = optional_param('city', '', PARAM_TEXT);
$selectedFaculty = optional_param('faculty', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT);
$limit = optional_param('per-page', 26, PARAM_INT);
$start = ($page - 1) * $limit;

// Initialize variables
$hoi_filter_f = ''; // HOI filter for alias 'f'
$hoi_filter_b = ''; // HOI filter for alias 'b'
$hoi_filter_u = ''; // HOI filter for mdl_user queries
$faculty_filter = ''; // Faculty filter for faculty users
$cityFilter = '';
$facultyCondition = '';
$availableCities = [];
$availableFaculties = [];
$cities = [];
$modules = [];
$moduleData = [];
$usertypeData = [];
$whereConditions = 'WHERE 1=1';
$totalRecords = 0;
$totalPages = 1;
$chartData = [];
$faculties = [];
$allTitles = [];
$enrollmentDetailedData = [];

// Set up page
$PAGE->set_url(new moodle_url("/local/amizone/reportchartdashboard.php"));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Report Dashboard");
$PAGE->set_heading("Report Dashboard");

// ============================================================================
// PERMISSION CHECKS - MODIFIED AS REQUESTED
// ============================================================================

// Check if user is logged in and has proper permissions
if (!isloggedin()) {
    redirect(get_login_url());
}

// Student users cannot access any reports - redirect them
if ($USER->usertype == 'Student') {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermissions', 'error', 'access dashboard'));
    echo $OUTPUT->footer();
    die;
}

// Faculty users can only view their own data
if ($USER->usertype == 'Faculty') {
    // Get faculty name based on user's idnumber
    $faculty_name = $DB->get_field_sql("
        SELECT TRIM(REPLACE(NAME, '\r', '')) 
        FROM my_faculty 
        WHERE ID = ?", [$USER->idnumber]);
    
    if ($faculty_name) {
        // Force filter to only show this faculty's data
        $selectedFaculty = $faculty_name;
        $faculty_filter = " AND TRIM(REPLACE(b.NAME, '\r', '')) = '" . $DB->sql_like_escape($faculty_name) . "' ";
    } else {
        // Faculty user but no faculty record found - show no data
        $selectedFaculty = 'NO_ACCESS';
        $faculty_filter = " AND 1=0 "; // This will return no results
    }
}

// Apply HOI institute filter ONLY if user is HOI
if ($USER->usertype == 'HOI') {
    // Filter for queries using alias 'f' (my_faculty as f)
    $hoi_filter_f = " AND f.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode') ";
    
    // Filter for queries using alias 'b' (my_faculty as b)
    $hoi_filter_b = " AND b.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode') ";
    
    // Filter for mdl_user queries - get cities under HOI
    $hoi_cities_result = $DB->get_records_sql("
        SELECT DISTINCT f.CITY 
        FROM my_faculty f 
        WHERE f.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode')
    ");
    
    $hoi_cities = [];
    foreach ($hoi_cities_result as $city_row) {
        $hoi_cities[] = "'" . $DB->sql_like_escape($city_row->city) . "'";
    }
    
    if (!empty($hoi_cities)) {
        $hoi_filter_u = " AND u.city IN (" . implode(',', $hoi_cities) . ") ";
    }
}

// Admin users have no restrictions (already handled by default behavior)

// 1. Get available cities for filter dropdown (filtered by HOI if applicable)
$cityFilterCondition = '';
if ($USER->usertype == 'HOI') {
    $cityFilterCondition = $hoi_filter_f;
} elseif ($USER->usertype == 'Faculty') {
    // For faculty users, get cities where their faculty exists
    $faculty_city_sql = "SELECT DISTINCT CITY FROM my_faculty WHERE TRIM(REPLACE(NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
    $faculty_cities = $DB->get_records_sql($faculty_city_sql);
    $availableCities = [];
    foreach ($faculty_cities as $city_row) {
        $availableCities[] = $city_row->city;
    }
} else {
    $cityQuery = $DB->get_records_sql("
        SELECT DISTINCT f.CITY as city 
        FROM my_faculty f 
        WHERE 1=1 
        $cityFilterCondition
        ORDER BY f.CITY
    ");

    foreach ($cityQuery as $cityRow) {
        $availableCities[] = $cityRow->city;
    }
}

// 2. Get available faculties for filter dropdown (filtered by HOI and city)
if ($USER->usertype != 'Faculty') {
    // If a faculty is selected, we need to get its city to filter the city dropdown
    $facultyCity = '';
    if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
        $facultyCity = $DB->get_field_sql("
            SELECT DISTINCT CITY 
            FROM my_faculty 
            WHERE TRIM(REPLACE(NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'
            LIMIT 1
        ");
    }
    
    $facultyCondition = "WHERE 1=1";
    if ($selectedCity) {
        $facultyCondition .= " AND f.city = '" . $DB->sql_like_escape($selectedCity) . "'";
    }
    if ($USER->usertype == 'HOI') {
        $facultyCondition .= $hoi_filter_f;
    }

    $facultyQuery = $DB->get_records_sql("
        SELECT DISTINCT TRIM(REPLACE(f.NAME, '\r', '')) as faculty_name
        FROM my_faculty f
        $facultyCondition
        ORDER BY faculty_name
    ");

    foreach ($facultyQuery as $facultyRow) {
        $availableFaculties[] = $facultyRow->faculty_name;
    }
    
    // If a faculty is selected, update the city filter to show only that faculty's city
    if ($facultyCity && !$selectedCity) {
        $selectedCity = $facultyCity;
    }
} else {
    // Faculty users only see themselves
    $availableFaculties = [$selectedFaculty];
}

// ============================================================================
// 3. CAMPUS WISE MODULE COUNT - NOW FILTERS BY CITY AND FACULTY
// ============================================================================
$moduleFilterConditions = "WHERE 1=1";

if ($selectedCity) {
    $moduleFilterConditions .= " AND f.city = '" . $DB->sql_like_escape($selectedCity) . "'";
}

if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
    $moduleFilterConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
}

if ($USER->usertype == 'HOI') {
    $moduleFilterConditions .= $hoi_filter_f;
} elseif ($USER->usertype == 'Faculty') {
    $moduleFilterConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
}

$modulesData = $DB->get_recordset_sql("
    SELECT
        f.city AS city,
        m.name AS modulename,
        COUNT(*) AS modulecount
    FROM my_faculty f
    JOIN my_moodle_course moc ON f.ID = moc.FACULTY
    JOIN mdl_course_modules cm ON moc.MOODLE_COURSE = cm.course
    JOIN mdl_modules m ON cm.module = m.id
    $moduleFilterConditions
    GROUP BY f.city, m.name
    ORDER BY f.city
");

foreach ($modulesData as $row) {
    $city = $row->city;
    $module = $row->modulename;
    $count = (int)$row->modulecount;

    if (!in_array($city, $cities)) {
        $cities[] = $city;
    }

    if (!in_array($module, $modules)) {
        $modules[] = $module;
    }

    if (!isset($moduleData[$module])) {
        $moduleData[$module] = [];
    }

    $moduleData[$module][$city] = $count;
}

// ============================================================================
// 4. MODULE DISTRIBUTION PIE CHART DATA FOR FACULTY AND HOI USERS
// ============================================================================
$moduleDistributionData = [];

if ($USER->usertype == 'Faculty' || $USER->usertype == 'HOI') {
    // Get module distribution data for pie chart
    $modulePieFilterConditions = "WHERE 1=1";
    
    if ($selectedCity) {
        $modulePieFilterConditions .= " AND f.city = '" . $DB->sql_like_escape($selectedCity) . "'";
    }
    
    if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
        $modulePieFilterConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
    }
    
    if ($USER->usertype == 'HOI') {
        $modulePieFilterConditions .= $hoi_filter_f;
    } elseif ($USER->usertype == 'Faculty') {
        $modulePieFilterConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
    }
    
    $moduleDistributionQuery = $DB->get_recordset_sql("
        SELECT
            m.name AS modulename,
            COUNT(*) AS modulecount
        FROM my_faculty f
        JOIN my_moodle_course moc ON f.ID = moc.FACULTY
        JOIN mdl_course_modules cm ON moc.MOODLE_COURSE = cm.course
        JOIN mdl_modules m ON cm.module = m.id
        $modulePieFilterConditions
        GROUP BY m.name
        ORDER BY modulecount DESC
    ");
    
    foreach ($moduleDistributionQuery as $row) {
        $moduleDistributionData[] = [
            'name' => $row->modulename,
            'y' => (int)$row->modulecount
        ];
    }
}

// ============================================================================
// 4. ROLE WISE USER DISTRIBUTION - FIXED FOR HOI
// ============================================================================

// When faculty is selected, we need to get users related to that faculty's courses
if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
    // Get usertype distribution for users related to the selected faculty
    $usertypeSql = "
        SELECT 
            u.usertype,
            COUNT(DISTINCT u.id) AS count
        FROM mdl_user u
        WHERE u.usertype IS NOT NULL
        AND (
            -- Include the selected faculty member
            (u.usertype = 'Faculty' AND u.idnumber IN (
                SELECT f.id FROM my_faculty f 
                WHERE TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'
                " . ($selectedCity ? "AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'" : "") . "
                " . ($USER->usertype == 'HOI' ? " AND f.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode') " : "") . "
            ))
            OR
            -- Include students enrolled in courses under this faculty
            (u.usertype = 'Student' AND u.id IN (
                SELECT DISTINCT ra.userid
                FROM my_section_students mss
                JOIN my_student ms ON mss.STUDENT = ms.ID
                JOIN my_moodle_course mmc ON mss.SECTION = mmc.ID
                JOIN my_faculty f ON mmc.FACULTY = f.ID
                JOIN mdl_context ctx ON mmc.MOODLE_COURSE = ctx.instanceid AND ctx.contextlevel = 50
                JOIN mdl_role_assignments ra ON ctx.id = ra.contextid
                    AND ra.roleid = 5
                    AND ra.userid = ms.MOODLESTUDENTID
                WHERE TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'
                " . ($selectedCity ? "AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'" : "") . "
                " . ($USER->usertype == 'HOI' ? " AND f.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode') " : "") . "
            ))
        )
        GROUP BY u.usertype
    ";
} else if ($USER->usertype == 'HOI') {
    // FIXED: For HOI users, get counts based on their institutions
    // Get Faculty count under HOI's institutions
    $hoiFacultySql = "
        SELECT 'Faculty' as usertype, COUNT(DISTINCT u.id) as count
        FROM mdl_user u
        WHERE u.usertype = 'Faculty'
        AND u.idnumber IN (
            SELECT f.ID 
            FROM my_faculty f 
            WHERE f.INSTITUTION IN (
                SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode'
            )
            " . ($selectedCity ? "AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'" : "") . "
        )
    ";
    
    // Get Student count under HOI's institutions (students enrolled in courses under HOI's faculties)
    $hoiStudentSql = "
        SELECT 'Student' as usertype, COUNT(DISTINCT u.id) as count
        FROM mdl_user u
        WHERE u.usertype = 'Student'
        AND u.id IN (
            SELECT DISTINCT ra.userid
            FROM my_section_students mss
            JOIN my_student ms ON mss.STUDENT = ms.ID
            JOIN my_moodle_course mmc ON mss.SECTION = mmc.ID
            JOIN my_faculty f ON mmc.FACULTY = f.ID
            JOIN mdl_context ctx ON mmc.MOODLE_COURSE = ctx.instanceid AND ctx.contextlevel = 50
            JOIN mdl_role_assignments ra ON ctx.id = ra.contextid
                AND ra.roleid = 5
                AND ra.userid = ms.MOODLESTUDENTID
            WHERE f.INSTITUTION IN (
                SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode'
            )
            " . ($selectedCity ? "AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'" : "") . "
        )
    ";
    
    // Get HOI count (other HOIs in the same institutions)
    $hoiHoiSql = "
        SELECT 'HOI' as usertype, COUNT(DISTINCT u.id) as count
        FROM mdl_user u
        WHERE u.usertype = 'HOI'
        AND u.usercode IN (
            SELECT h.sStaffCode
            FROM my_hoi h
            WHERE h.iinstituteid IN (
                SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode'
            )
        )
    ";
    
    // Combine all queries
    $usertypeSql = "
        SELECT usertype, SUM(count) as count
        FROM (
            ($hoiFacultySql)
            UNION ALL
            ($hoiStudentSql)
            UNION ALL
            ($hoiHoiSql)
        ) combined
        GROUP BY usertype
        HAVING count > 0
    ";
} else {
    // Original query with city filter only (for Admin)
    $usertypeFilter = '';
    if ($selectedCity) {
        $usertypeFilter = " AND u.city = '" . $DB->sql_like_escape($selectedCity) . "'";
    }

    $usertypeSql = "
        SELECT 
            u.usertype,
            COUNT(*) AS count
        FROM mdl_user u
        LEFT JOIN my_student s 
           ON s.id = u.idnumber
        LEFT JOIN my_faculty f 
           ON f.id = u.idnumber
        WHERE u.usertype IS NOT NULL
        $usertypeFilter
        GROUP BY u.usertype
    ";
}

$usertypeResult = $DB->get_recordset_sql($usertypeSql);

foreach ($usertypeResult as $row) {
    $usertypeData[] = [
        'name' => $row->usertype,
        'y' => (int)$row->count
    ];
}

// 5. Build WHERE conditions for pagination query
$whereConditions = "WHERE 1=1";
if ($selectedCity) {
    $whereConditions .= " AND b.CITY = '" . $DB->sql_like_escape($selectedCity) . "'";
}
if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
    $whereConditions .= " AND TRIM(REPLACE(b.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
}
if ($USER->usertype == 'HOI') {
    $whereConditions .= $hoi_filter_b;
} elseif ($USER->usertype == 'Faculty') {
    $whereConditions .= $faculty_filter;
}

// Calculate total records for pagination
$countSql = "
    SELECT COUNT(*) as total FROM (
        SELECT a.ID
        FROM my_moodle_course a
        JOIN my_faculty b ON a.FACULTY = b.ID
        $whereConditions
        GROUP BY a.ID
    ) as count_table
";

$totalRecords = $DB->count_records_sql($countSql);
$totalPages = ceil($totalRecords / $limit);

// 6. Fetch data for Students per Course by Faculty with Pagination
$sql = "
SELECT b.NAME as faculty_name,
        a.TITLE as section_name,
        b.CITY as campus,
        COALESCE(student_counts.total_students, 0) as total_students,
        COALESCE(student_counts.enrolled_students, 0) as enrolled_students
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
GROUP BY a.ID, b.NAME, a.TITLE, b.CITY, student_counts.total_students, student_counts.enrolled_students
ORDER BY faculty_name
LIMIT $start, $limit
";

$result = $DB->get_recordset_sql($sql);

foreach ($result as $row) {
    $faculty = trim(str_replace("\r", '', $row->faculty_name));
    $title = $row->section_name;
    $campus = $row->campus;
    $totalStudents = (int)$row->total_students;
    $enrolledStudents = (int)$row->enrolled_students;

    if (!in_array($faculty, $faculties)) $faculties[] = $faculty;
    if (!in_array($title, $allTitles)) $allTitles[] = $title;
    if (!isset($chartData[$faculty])) $chartData[$faculty] = [];

    $chartData[$faculty][$title] = [
        'total' => $totalStudents,
        'enrolled' => $enrolledStudents
    ];
    
    $enrollmentDetailedData[] = [
        'faculty_name' => $faculty,
        'course_name' => $title,
        'campus' => $campus,
        'total_students' => $totalStudents,
        'enrolled_students' => $enrolledStudents,
        'unenrolled_students' => $totalStudents - $enrolledStudents,
        'enrollment_percentage' => $totalStudents > 0 ? round(($enrolledStudents / $totalStudents) * 100, 2) : 0
    ];
}

// ============================================================================
// 7. DASHBOARD CARDS QUERIES - DYNAMICALLY FILTERS BY CITY AND/OR FACULTY
// ============================================================================

// Build common filter conditions that apply to ALL card queries
$cardFilterConditions = "WHERE 1=1";

// Add City filter if selected
if ($selectedCity) {
    $cardFilterConditions .= " AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'";
}

// Add Faculty filter if selected
if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
    $cardFilterConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
}

// Add HOI filter if user is HOI
if ($USER->usertype == 'HOI') {
    $cardFilterConditions .= $hoi_filter_f;
} elseif ($USER->usertype == 'Faculty') {
    $cardFilterConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
}

// 7.1. Total Faculty Query
$facultyCountSql = "
    SELECT COUNT(DISTINCT f.ID) 
    FROM my_faculty f 
    $cardFilterConditions
";
$totalFaculty = $DB->count_records_sql($facultyCountSql);

// 7.2. Total Courses Query
$totalCoursesSql = "
    SELECT COUNT(DISTINCT a.ID) AS total_courses
    FROM my_moodle_course a
    JOIN my_faculty b ON a.FACULTY = b.ID
    $whereConditions
";
$totalCourses = $DB->count_records_sql($totalCoursesSql);

// 7.3. Total Students (Registered) Query
if ($selectedFaculty || $selectedCity || $USER->usertype == 'HOI' || $USER->usertype == 'Faculty') {
    $registeredStudentSql = "
        SELECT COUNT(mss.STUDENT)
        FROM my_section_students mss
        JOIN my_moodle_course mmc ON mss.SECTION = mmc.ID
        JOIN my_faculty f ON mmc.FACULTY = f.ID
        $cardFilterConditions
    ";
} else {
    $registeredStudentSql = "
        SELECT COUNT(DISTINCT s.ID)
        FROM my_student s
        JOIN mdl_user u ON s.id = u.idnumber
        WHERE u.usertype = 'Student'
    ";
}
$totalStudents = $DB->count_records_sql($registeredStudentSql);

// 7.4. Total Students (Enrolled) Query  
$enrolledConditions = "WHERE 1=1";

if ($selectedCity) {
    $enrolledConditions .= " AND f.CITY = '" . $DB->sql_like_escape($selectedCity) . "'";
}

if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
    $enrolledConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
}

if ($USER->usertype == 'HOI') {
    $enrolledConditions .= $hoi_filter_f;
} elseif ($USER->usertype == 'Faculty') {
    $enrolledConditions .= " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
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
$totalEnrolled = $DB->count_records_sql($totalEnrolledSql);

// Prepare data for JSON export
$dashboardSummary = [
    'total_faculty' => $totalFaculty,
    'total_courses' => $totalCourses,
    'total_students_registered' => $totalStudents,
    'total_students_enrolled' => $totalEnrolled
];

$enrollmentDetailedDataJson = json_encode($enrollmentDetailedData);
$dashboardSummaryJson = json_encode($dashboardSummary);
$citiesJson = json_encode($cities);
$modulesJson = json_encode($modules);
$moduleDataJson = json_encode($moduleData);
$usertypeDataJson = json_encode($usertypeData);
$moduleDistributionDataJson = json_encode($moduleDistributionData);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Report Dashboard</title>
    <?php echo $OUTPUT->standard_head_html() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="https://code.highcharts.com/modules/exporting.js"></script>
    <script src="https://code.highcharts.com/modules/export-data.js"></script>
    <script src="https://code.highcharts.com/modules/accessibility.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }

        h2 {
            text-align: center;
            margin-top: 20px;
            color: #333;
        }

        .dashboard-header {
            background-color: #2c3e50;
            color: white;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            margin: 0;
            flex-grow: 1;
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

        .download-success {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1050;
        }

        .alert-success {
            border-left: 4px solid #28a745;
        }

        .dashboard-container {
            width: 95%;
            margin: 0 auto;
        }

        .filter-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-left {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filter-right {
            display: flex;
            gap: 10px;
        }

        .filter-container label {
            font-weight: bold;
            font-size: 16px;
            min-width: max-content;
        }

        .filter-container select {
            padding: 8px 15px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
            min-width: 200px;
        }

        .filter-container button {
            padding: 8px 15px;
            background-color: #007BFF;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }

        .filter-container button:hover {
            background-color: #0056b3;
        }

        .filter-container button.clear-btn {
            background-color: #6c757d;
        }

        .filter-container button.clear-btn:hover {
            background-color:rgb(209, 92, 92);
        }

        .card-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
        }

        .dashboard-card {
            flex: 1;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            transition: transform 0.3s;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
        }

        .card-title {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 5px;
        }

        .card-value {
            font-size: 2.5rem;
            font-weight: bold;
            color: #007BFF;
        }

        .card-icon {
            font-size: 1.5rem;
            margin-right: 10px;
            vertical-align: middle;
        }

        .chart-row {
            display: flex;
            margin-bottom: 20px;
            gap: 10px;
        }

        .chart-col-left {
            width: 60%;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 15px;
        }

        .chart-col-right {
            width: 40%;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 15px;
        }

        .chart-full {
            width: 100%;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            padding: 15px;
            margin-bottom: 20px;
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .chart-header h2 {
            margin: 0;
            text-align: left;
        }

        .chart-filter-badge {
            background-color: #e7f3ff;
            color: #007BFF;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        #line-chart-container {
            height: 400px;
        }

        #pie-chart-container {
            height: 400px;
        }

        .bar-chart-container {
            height: 600px;
            width: 100%;
            margin: 0 auto;
        }

        .pagination {
            text-align: center;
            margin: 10px;
        }

        .pagination a, .pagination span {
            display: inline-block;
            padding: 8px 14px;
            margin: 0 4px;
            border-radius: 4px;
            font-weight: bold;
            text-decoration: none;
        }

        .pagination a {
            background-color: #007BFF;
            color: white;
        }

        .pagination a:hover {
            background-color: #0056b3;
        }

        .pagination span {
            background-color: #ccc;
            color: black;
        }

        .no-data-message {
            text-align: center;
            padding: 50px;
            font-size: 18px;
            color: #666;
        }

        .highcharts-point {
            cursor: pointer;
        }

        .campus-point-tooltip {
            position: absolute;
            background-color: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            pointer-events: none;
            z-index: 1000;
            display: none;
        }

        .active-filters {
            background-color: #e7f3ff;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .filter-tag {
            display: inline-block;
            background-color: #007BFF;
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            margin-right: 8px;
            font-size: 12px;
        }

        .filter-info {
            font-size: 12px;
            color: #666;
            font-style: italic;
            margin-top: 5px;
        }

        .user-type-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #ffc107;
            color: #212529;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>

<?php echo $OUTPUT->header(); ?>

<div class="dashboard-header">
    <div></div>
    <h1>Report Dashboard 
        <?php if ($USER->usertype == 'Faculty'): ?>
        <span style="font-size: 16px; display: block; margin-top: 5px;">(Viewing: <?php echo htmlspecialchars($selectedFaculty); ?>)</span>
        <?php endif; ?>
    </h1>
    <?php if (!empty($enrollmentDetailedData) && $USER->usertype != 'Student'): ?>
    <div class="dropdown">
        <button class="download-btn dropdown-toggle" type="button" id="downloadDropdown" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fa fa-download"></i> Download Report
        </button>
        <ul class="dropdown-menu" aria-labelledby="downloadDropdown">
            <li><a class="dropdown-item" href="#" onclick="downloadDashboardReport('csv')">
                <i class="fa fa-file-text"></i> Download as CSV
            </a></li>
            <li><a class="dropdown-item" href="#" onclick="downloadDashboardReport('excel')">
                <i class="fa fa-file-excel"></i> Download as Excel
            </a></li>
            <li><a class="dropdown-item" href="#" onclick="downloadDashboardReport('json')">
                <i class="fa fa-code"></i> Download as JSON
            </a></li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<div id="downloadSuccessMessage" class="alert alert-success download-success" role="alert" style="display: none;">
    <i class="fa fa-check-circle"></i> Download complete! Saved as: <strong id="downloadFilename"></strong>
</div>

<div class="dashboard-container">
    <?php if ($USER->usertype == 'Faculty'): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> You are viewing your data only.
    </div>
    <?php endif; ?>

    <?php if ($USER->usertype != 'Faculty'): ?>
    <div class="filter-container">
        <div class="filter-left">
            <form id="globalFilterForm" method="get" action="<?php echo $CFG->wwwroot ?>/local/amizone/reportchartdashboard.php" style="display: contents;">
                <div class="filter-group">
                    <label for="cityFilter">Filter by City:</label>
                    <select id="cityFilter" name="city" onchange="updateFacultyDropdown()">
                        <option value="">All Campus</option>
                        <?php foreach($availableCities as $city): ?>
                        <option value="<?php echo htmlspecialchars($city); ?>" <?php echo ($selectedCity === $city) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($city); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="facultyFilter">Filter by Faculty:</label>
                    <select id="facultyFilter" name="faculty" class="select2" onchange="updateCityDropdown()">
                        <option value="">All Faculty</option>
                        <?php foreach($availableFaculties as $faculty): ?>
                        <option value="<?php echo htmlspecialchars($faculty); ?>" <?php echo ($selectedFaculty === $faculty) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($faculty); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <div class="filter-right">
            <button type="button" onclick="applyFilters()">Apply Filters</button>
            <?php if(!empty($selectedCity) || !empty($selectedFaculty)): ?>
            <button type="button" class="clear-btn" onclick="clearAllFilters()">Clear All Filters</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if(!empty($selectedCity) || !empty($selectedFaculty)): ?>
    <div class="active-filters">
        <strong>Active Filters: </strong>
        <?php if(!empty($selectedCity)): ?>
        <span class="filter-tag">City: <?php echo htmlspecialchars($selectedCity); ?></span>
        <?php endif; ?>
        <?php if(!empty($selectedFaculty)): ?>
        <span class="filter-tag">Faculty: <?php echo htmlspecialchars($selectedFaculty); ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="card-row">
        <div class="dashboard-card" style="border-left: 5px solid #007BFF;">
            <div class="card-title"><i class="fas fa-chalkboard-teacher card-icon" style="color:#007BFF;"></i>Total Faculty</div>
            <div class="card-value"><?php echo $totalFaculty; ?></div>
            <div class="filter-info">
                <?php 
                if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
                    echo 'Selected: ' . htmlspecialchars($selectedFaculty);
                } elseif ($selectedCity) {
                    echo 'City: ' . htmlspecialchars($selectedCity);
                } else {
                    echo 'All Faculties';
                }
                ?>
            </div>
        </div>

        <div class="dashboard-card" style="border-left: 5px solid #FFCE56;">
            <div class="card-title"><i class="fas fa-book card-icon" style="color:#FFCE56;"></i>Total Courses</div>
            <div class="card-value"><?php echo $totalCourses; ?></div>
            <div class="filter-info">
                <?php 
                if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
                    echo 'Under: ' . htmlspecialchars($selectedFaculty);
                } elseif ($selectedCity) {
                    echo 'In: ' . htmlspecialchars($selectedCity);
                } else {
                    echo 'All Courses';
                }
                ?>
            </div>
        </div>

        <div class="dashboard-card" style="border-left: 5px solid #28a745;">
            <div class="card-title"><i class="fas fa-user-graduate card-icon" style="color:#28a745;"></i>Total Students (Registered)</div>
            <div class="card-value"><?php echo $totalStudents; ?></div>
            <div class="filter-info">
                <?php 
                if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
                    echo 'Under: ' . htmlspecialchars($selectedFaculty);
                } elseif ($selectedCity) {
                    echo 'In: ' . htmlspecialchars($selectedCity);
                } else {
                    echo 'All Students';
                }
                ?>
            </div>
        </div>

        <div class="dashboard-card" style="border-left: 5px solid #dc3545;">
            <div class="card-title"><i class="fas fa-user-check card-icon" style="color:#dc3545;"></i>Total Students (Enrolled)</div>
            <div class="card-value"><?php echo $totalEnrolled; ?></div>
            <div class="filter-info">
                <?php 
                if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
                    echo 'Enrolled under: ' . htmlspecialchars($selectedFaculty);
                } elseif ($selectedCity) {
                    echo 'Enrolled in: ' . htmlspecialchars($selectedCity);
                } else {
                    echo 'All Enrolled Students';
                }
                ?>
            </div>
        </div>
    </div>

    <div class="chart-row">
        <div class="chart-col-left">
            <h2>
                <?php if ($USER->usertype == 'Faculty' || $USER->usertype == 'HOI'): ?>
                Module Distribution
                <?php else: ?>
                Campus Wise Module Count
                <?php endif; ?>
            </h2>
            <?php if ($USER->usertype == 'Faculty' || $USER->usertype == 'HOI'): ?>
                <?php if(!empty($moduleDistributionData)): ?>
                <div id="module-pie-chart-container"></div>
                <?php else: ?>
                <div class="no-data-message">No module data available for the selected filter.</div>
                <?php endif; ?>
            <?php else: ?>
                <?php if(!empty($cities)): ?>
                <div id="line-chart-container"></div>
                <div class="campus-point-tooltip" id="campus-tooltip">Click to view faculty details</div>
                <?php else: ?>
                <div class="no-data-message">No data available for the selected filter.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="chart-col-right">
            <h2>Role wise User Distribution</h2>
            <?php if(!empty($usertypeData)): ?>
            <div id="pie-chart-container"></div>
            <?php else: ?>
            <div class="no-data-message">No data available for the selected filter.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="chart-full">
        <h2>Students per Course by Faculty</h2>

        <?php if(!empty($faculties) && !empty($allTitles)): ?>
        <div class="bar-chart-container">
            <canvas id="facultyChart"></canvas>
        </div>

        <div class="pagination">
            <?php
            $filterParams = "";
            if ($selectedCity) $filterParams .= "&city=" . urlencode($selectedCity);
            if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') $filterParams .= "&faculty=" . urlencode($selectedFaculty);

            $range = 2;
            if ($page > 1) {
                echo '<a href="' . $CFG->wwwroot . '/local/amizone/reportchartdashboard.php?page=' . ($page - 1) . $filterParams . '">&laquo; Prev</a>';
            }
            for ($i = max(1, $page - $range); $i <= min($totalPages, $page + $range); $i++) {
                if ($i === $page) {
                    echo '<span>' . $i . '</span>';
                } else {
                    echo '<a href="' . $CFG->wwwroot . '/local/amizone/reportchartdashboard.php?page=' . $i . $filterParams . '">' . $i . '</a>';
                }
            }
            if ($page < $totalPages) {
                echo '<a href="' . $CFG->wwwroot . '/local/amizone/reportchartdashboard.php?page=' . ($page + 1) . $filterParams . '">Next &raquo;</a>';
            }
            ?>
        </div>
        <?php else: ?>
        <div class="no-data-message">No data available for the selected filter.</div>
        <?php endif; ?>
    </div>
</div>

<script>
// Data for download functionality
const enrollmentDetailedData = <?php echo $enrollmentDetailedDataJson; ?>;
const dashboardSummary = <?php echo $dashboardSummaryJson; ?>;
const cities = <?php echo $citiesJson; ?>;
const modules = <?php echo $modulesJson; ?>;
const moduleData = <?php echo $moduleDataJson; ?>;
const usertypeData = <?php echo $usertypeDataJson; ?>;
const moduleDistributionData = <?php echo $moduleDistributionDataJson; ?>;
const selectedCity = <?php echo json_encode($selectedCity); ?>;
const selectedFaculty = <?php echo json_encode($selectedFaculty); ?>;
const userType = <?php echo json_encode($USER->usertype); ?>;

function showDownloadSuccess(format, filename) {
    const messageBox = document.getElementById('downloadSuccessMessage');
    const filenameSpan = document.getElementById('downloadFilename');
    
    filenameSpan.textContent = filename;
    messageBox.style.display = 'block';
    
    setTimeout(() => {
        messageBox.style.display = 'none';
    }, 5000);
}

function applyFilters() {
    document.getElementById('globalFilterForm').submit();
}

function clearAllFilters() {
    window.location.href = '<?php echo $CFG->wwwroot ?>/local/amizone/reportchartdashboard.php';
}

function updateFacultyDropdown() {
    const cityFilter = document.getElementById('cityFilter');
    const facultyFilter = document.getElementById('facultyFilter');
    const selectedCity = cityFilter.value;
    
    if (!selectedCity) {
        // If no city selected, show all faculties
        fetch('<?php echo $CFG->wwwroot ?>/local/amizone/ajax.php?action=get_all_faculties')
            .then(response => response.json())
            .then(data => {
                updateFacultyOptions(data);
            })
            .catch(error => console.error('Error:', error));
    } else {
        // Get faculties for selected city
        fetch('<?php echo $CFG->wwwroot ?>/local/amizone/ajax.php?action=get_faculties_by_city&city=' + encodeURIComponent(selectedCity))
            .then(response => response.json())
            .then(data => {
                updateFacultyOptions(data);
            })
            .catch(error => console.error('Error:', error));
    }
}

function updateCityDropdown() {
    const facultyFilter = document.getElementById('facultyFilter');
    const cityFilter = document.getElementById('cityFilter');
    const selectedFaculty = facultyFilter.value;
    
    if (!selectedFaculty) {
        // If no faculty selected, show all cities
        fetch('<?php echo $CFG->wwwroot ?>/local/amizone/ajax.php?action=get_all_cities')
            .then(response => response.json())
            .then(data => {
                updateCityOptions(data);
            })
            .catch(error => console.error('Error:', error));
    } else {
        // Get city for selected faculty
        fetch('<?php echo $CFG->wwwroot ?>/local/amizone/ajax.php?action=get_city_by_faculty&faculty=' + encodeURIComponent(selectedFaculty))
            .then(response => response.json())
            .then(data => {
                updateCityOptions(data);
            })
            .catch(error => console.error('Error:', error));
    }
}

function updateFacultyOptions(faculties) {
    const facultyFilter = document.getElementById('facultyFilter');
    const currentValue = facultyFilter.value;
    
    // Clear existing options except the first one
    while (facultyFilter.options.length > 1) {
        facultyFilter.remove(1);
    }
    
    // Add new options
    faculties.forEach(faculty => {
        const option = document.createElement('option');
        option.value = faculty;
        option.textContent = faculty;
        facultyFilter.appendChild(option);
    });
    
    // Restore selected value if it still exists
    if (currentValue && Array.from(facultyFilter.options).some(opt => opt.value === currentValue)) {
        facultyFilter.value = currentValue;
    } else {
        facultyFilter.value = '';
    }
    
    // Update Select2
    $(facultyFilter).trigger('change.select2');
}

function updateCityOptions(cities) {
    const cityFilter = document.getElementById('cityFilter');
    const currentValue = cityFilter.value;
    
    // Clear existing options except the first one
    while (cityFilter.options.length > 1) {
        cityFilter.remove(1);
    }
    
    // Add new options
    cities.forEach(city => {
        const option = document.createElement('option');
        option.value = city;
        option.textContent = city;
        cityFilter.appendChild(option);
    });
    
    // Restore selected value if it still exists
    if (currentValue && Array.from(cityFilter.options).some(opt => opt.value === currentValue)) {
        cityFilter.value = currentValue;
    } else {
        cityFilter.value = '';
    }
}

function downloadDashboardReport(format = 'csv') {
    const now = new Date();
    const timestamp = now.getFullYear() + '-' + 
                     String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                     String(now.getDate()).padStart(2, '0') + '_' + 
                     String(now.getHours()).padStart(2, '0') + '-' + 
                     String(now.getMinutes()).padStart(2, '0');
    
    const cityPart = selectedCity ? '_' + selectedCity.replace(/[^a-zA-Z0-9]/g, '_') : '';
    const facultyPart = selectedFaculty ? '_' + selectedFaculty.replace(/[^a-zA-Z0-9]/g, '_') : '';
    
    let content, mimeType, filename;

    switch(format) {
        case 'csv':
            content = generateDashboardCSV();
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Dashboard_Report${cityPart}${facultyPart}_${timestamp}.csv`;
            break;
        case 'excel':
            content = "\uFEFF" + generateDashboardCSV();
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Dashboard_Report${cityPart}${facultyPart}_${timestamp}.csv`;
            break;
        case 'json':
            content = JSON.stringify({
                report_title: 'Dashboard Report',
                filters: {
                    city: selectedCity || 'All Cities',
                    faculty: selectedFaculty || 'All Faculties'
                },
                generated_at: now.toISOString(),
                summary_statistics: dashboardSummary,
                module_data_by_city: {
                    cities: cities,
                    modules: modules,
                    data: moduleData
                },
                usertype_distribution: usertypeData,
                enrollment_details: enrollmentDetailedData
            }, null, 2);
            mimeType = 'application/json;charset=utf-8;';
            filename = `Dashboard_Report${cityPart}${facultyPart}_${timestamp}.json`;
            break;
        default:
            content = generateDashboardCSV();
            mimeType = 'text/csv;charset=utf-8;';
            filename = `Dashboard_Report${cityPart}${facultyPart}_${timestamp}.csv`;
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

function generateDashboardCSV() {
    let csvContent = "Dashboard Report\n";
    csvContent += `City Filter: ${selectedCity || 'All Cities'}\n`;
    csvContent += `Faculty Filter: ${selectedFaculty || 'All Faculties'}\n\n`;
    
    // Summary Statistics
    csvContent += "Summary Statistics\n";
    csvContent += `Total Faculty,${dashboardSummary.total_faculty}\n`;
    csvContent += `Total Courses,${dashboardSummary.total_courses}\n`;
    csvContent += `Total Students (Registered),${dashboardSummary.total_students_registered}\n`;
    csvContent += `Total Students (Enrolled),${dashboardSummary.total_students_enrolled}\n\n`;
    
    // Module Data by City
    if (cities.length > 0 && modules.length > 0) {
        csvContent += "Campus-wise Module Count\n";
        csvContent += "Module Name";
        cities.forEach(city => {
            csvContent += "," + JSON.stringify(city.replace(/"/g, '""'));
        });
        csvContent += "\n";
        
        modules.forEach(module => {
            csvContent += JSON.stringify(module.replace(/"/g, '""'));
            cities.forEach(city => {
                const count = moduleData[module] && moduleData[module][city] ? moduleData[module][city] : 0;
                csvContent += "," + count;
            });
            csvContent += "\n";
        });
        csvContent += "\n";
    }
    
    // User Type Distribution
    if (usertypeData.length > 0) {
        csvContent += "Role-wise User Distribution\n";
        csvContent += "User Type,Count\n";
        usertypeData.forEach(item => {
            csvContent += `${JSON.stringify(item.name.replace(/"/g, '""'))},${item.y}\n`;
        });
        csvContent += "\n";
    }
    
    // Enrollment Details
    if (enrollmentDetailedData.length > 0) {
        csvContent += "Detailed Enrollment Data by Faculty and Course\n";
        csvContent += "Faculty Name,Course Name,Campus,Total Students,Enrolled Students,Unenrolled Students,Enrollment Rate (%)\n";
        
        enrollmentDetailedData.forEach(row => {
            csvContent += `${JSON.stringify(row.faculty_name.replace(/"/g, '""'))},`;
            csvContent += `${JSON.stringify(row.course_name.replace(/"/g, '""'))},`;
            csvContent += `${JSON.stringify(row.campus.replace(/"/g, '""'))},`;
            csvContent += `${row.total_students},`;
            csvContent += `${row.enrolled_students},`;
            csvContent += `${row.unenrolled_students},`;
            csvContent += `${row.enrollment_percentage}\n`;
        });
    }
    
    return csvContent;
}

<?php if($USER->usertype == 'Faculty' || $USER->usertype == 'HOI'): ?>
// Pie Chart - Module Distribution for Faculty and HOI Users
<?php if(!empty($moduleDistributionData)): ?>
Highcharts.chart('module-pie-chart-container', {
    chart: {
        type: 'pie'
    },
    title: {
        text: null
    },
    credits: {
        enabled: false
    },
    exporting: {
        enabled: true
    },
    tooltip: {
        headerFormat: '',
        pointFormat: '<span style="color:{point.color}">\u25cf</span> {point.name}: <b>{point.y} modules ({point.percentage:.1f}%)</b>'
    },
    accessibility: {
        point: {
            valueSuffix: 'modules'
        }
    },
    plotOptions: {
        pie: {
            allowPointSelect: true,
            borderWidth: 2,
            cursor: 'pointer',
            dataLabels: {
                enabled: true,
                format: '<b>{point.name}</b><br>{point.y} modules',
                distance: 20,
                style: {
                    fontSize: '13px'
                }
            },
            point: {
                events: {
                    click: function() {
                        // Open faculty-modules.php with appropriate filters
                        let url = '<?php echo $CFG->wwwroot ?>/local/amizone/faculty-modules.php';
                        <?php if(!empty($selectedCity)): ?>
                        url += '?city=' + encodeURIComponent('<?php echo $selectedCity; ?>');
                        <?php endif; ?>
                        <?php if(!empty($selectedFaculty) && $selectedFaculty != 'NO_ACCESS'): ?>
                        url += (url.includes('?') ? '&' : '?') + 'faculty=' + encodeURIComponent('<?php echo $selectedFaculty; ?>');
                        <?php endif; ?>
                        window.open(url, '_blank');
                    }
                }
            }
        }
    },
    series: [{
        colorByPoint: true,
        data: <?php echo json_encode($moduleDistributionData); ?>
    }]
});
<?php endif; ?>
<?php else: ?>
// Line Chart - City Wise Module Count with Clickable Points (for Admin users)
<?php if(!empty($cities)): ?>
Highcharts.chart('line-chart-container', {
    chart: {
        type: 'line'
    },
    title: {
        text: null
    },
    credits: {
        enabled: false
    },
    exporting: {
        enabled: true
    },
    xAxis: {
        categories: <?php echo json_encode($cities); ?>,
        title: {
            text: 'Campus',
            style: {
                fontSize: '16px'
            }
        },
        labels: {
            style: {
                fontSize: '14px'
            }
        },
        crosshair: true
    },
    yAxis: {
        min: 0,
        title: {
            text: 'Module Count',
            style: {
                fontSize: '16px'
            }
        },
        labels: {
            style: {
                fontSize: '14px'
            }
        }
    },
    tooltip: {
        headerFormat: '<span style="font-size:10px">{point.key}</span><table>',
        pointFormat: '<tr><td style="color:{series.color};padding:0">{series.name}: </td>' +
            '<td style="padding:0"><b>{point.y}</b></td></tr>',
        footerFormat: '</table><br><span style="font-size:10px"><b>Click to view faculty details</b></span>',
        shared: true,
        useHTML: true
    },
    plotOptions: {
        line: {
            marker: {
                radius: 4,
                lineColor: '#666666',
                lineWidth: 1
            },
            dataLabels: {
                enabled: true
            }
        },
        series: {
            cursor: 'pointer',
            point: {
                events: {
                    click: function() {
                        const city = this.category;
                        window.open('<?php echo $CFG->wwwroot ?>/local/amizone/faculty-modules.php?city=' + encodeURIComponent(city), '_blank');
                    },
                    mouseOver: function() {
                        const tooltip = document.getElementById('campus-tooltip');
                        tooltip.style.display = 'block';
                        tooltip.style.left = (this.plotX + 10) + 'px';
                        tooltip.style.top = (this.plotY + 10) + 'px';
                    },
                    mouseOut: function() {
                        document.getElementById('campus-tooltip').style.display = 'none';
                    }
                }
            }
        }
    },
    series: [
        <?php
        $colors = ['#36A2EB', '#9966FF', '#4BC0C0', '#FF6384', '#FFCE56', '#FF9F40'];
        $colorIndex = 0;
        $modulesToShow = array_slice($modules, 0);

        foreach ($modulesToShow as $index => $module):
            $moduleValues = [];
            foreach ($cities as $city) {
                $moduleValues[] = isset($moduleData[$module][$city]) ? $moduleData[$module][$city] : 0;
            }
            $color = $colors[$colorIndex % count($colors)];
            $colorIndex++;
        ?>
        {
            name: <?php echo json_encode($module); ?>,
            data: <?php echo json_encode($moduleValues); ?>,
            color: '<?php echo $color; ?>',
            marker: {
                symbol: 'circle'
            }
        }<?php echo ($index < count($modulesToShow) - 1) ? ',' : ''; ?>
        <?php endforeach; ?>
    ]
});
<?php endif; ?>
<?php endif; ?>

<?php if(!empty($usertypeData)): ?>
// Pie Chart - Usertype Distribution
Highcharts.chart('pie-chart-container', {
    chart: {
        type: 'pie'
    },
    title: {
        text: null
    },
    credits: {
        enabled: false
    },
    exporting: {
        enabled: true
    },
    tooltip: {
        headerFormat: '',
        pointFormat: '<span style="color:{point.color}">\u25cf</span> {point.name}: <b>{point.percentage:.1f}%</b>'
    },
    accessibility: {
        point: {
            valueSuffix: 'number'
        }
    },
    plotOptions: {
        pie: {
            allowPointSelect: true,
            borderWidth: 2,
            cursor: 'pointer',
            dataLabels: {
                enabled: true,
                format: '<b>{point.name}</b><br>{point.y} users',
                distance: 20,
                style: {
                    fontSize: '13px'
                }
            }
        }
    },
    series: [{
        colorByPoint: true,
        data: <?php echo json_encode($usertypeData); ?>
    }]
});
<?php endif; ?>

<?php if(!empty($faculties) && !empty($allTitles)): ?>
// Bar Chart - Students per Course by Faculty (with clickable bars)
const faculties = <?php echo json_encode($faculties); ?>;
const allTitles = <?php echo json_encode($allTitles); ?>;
const chartData = <?php echo json_encode($chartData); ?>;

function generateColors(count) {
    const colors = ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40'];
    for (let i = colors.length; i < count; i++) {
        const h = Math.floor(Math.random() * 360);
        colors.push(`hsl(${h}, 70%, 50%)`);
    }
    return colors;
}

const columnDatasets = [];
const colors = generateColors(allTitles.length);

allTitles.forEach((title, i) => {
    const data = faculties.map(f => {
        const courseData = chartData[f]?.[title];
        return courseData ? courseData.total : 0;
    });

    columnDatasets.push({
        label: title,
        originalTitle: title,
        data: data,
        enrolledData: faculties.map(f => chartData[f]?.[title] ? chartData[f][title].enrolled : 0),
        backgroundColor: colors[i],
        barThickness: 15,
        maxBarThickness: 25,
        borderWidth: 1,
        borderColor: '#fff',
        type: 'bar',
        order: 2
    });
});

Chart.register(ChartDataLabels);
const ctx = document.getElementById('facultyChart').getContext('2d');
const myChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: faculties,
        datasets: columnDatasets
    },
    plugins: [ChartDataLabels],
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            datalabels: {
                color: '#000',
                anchor: 'end',
                align: 'top',
                font: { weight: 'bold', size: 10 },
                formatter: (value, context) => {
                    if (context.datasetIndex < columnDatasets.length) {
                        return value > 0 ? value : '';
                    }
                    return '';
                },
                display: context => context.dataset.data[context.dataIndex] > 0
            },
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    title: ctx => `Faculty: ${ctx[0].label}`,
                    label: ctx => {
                        const enrolledCount = ctx.dataset.enrolledData[ctx.dataIndex];
                        return [
                            `Course: ${ctx.dataset.originalTitle}`,
                            `Total Students: ${ctx.parsed.y}`,
                            `Enrolled Students: ${enrolledCount}`,
                            '',
                            'Click to view detailed enrollment report'
                        ];
                    }
                }
            }
        },
        scales: {
            x: {
                title: { display: true, text: 'Faculty', font: { size: 16, weight: 'bold' } },
                ticks: { maxRotation: 45, minRotation: 45, font: { size: 14 } }
            },
            y: {
                beginAtZero: true,
                title: { display: true, text: 'Number of Students', font: { size: 16, weight: 'bold' } },
                ticks: { precision: 0, font: { size: 14 } }
            }
        },
        onClick: (event, activeElements) => {
            if (activeElements.length > 0) {
                const datasetIndex = activeElements[0].datasetIndex;
                const index = activeElements[0].index;
                const facultyName = faculties[index];

                let url = '<?php echo $CFG->wwwroot ?>/local/amizone/enrolled.php?faculty=' + encodeURIComponent(facultyName);
                <?php if(!empty($selectedCity)): ?>
                url += '&city=<?php echo urlencode($selectedCity); ?>';
                <?php endif; ?>

                window.open(url, '_blank');
            }
        },
        onHover: (event, activeElements) => {
            event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
        }
    }
});
<?php endif; ?>

$(document).ready(function() {
    <?php if ($USER->usertype != 'Faculty'): ?>
    $('#facultyFilter').select2({
        placeholder: "Select a faculty",
        allowClear: true,
        width: '250px'
    });
    
    // Initialize with correct filters based on URL parameters
    <?php if ($selectedCity && $selectedFaculty): ?>
    // If both filters are present, ensure they're consistent
    setTimeout(() => {
        updateFacultyDropdown();
        updateCityDropdown();
    }, 100);
    <?php endif; ?>
    <?php endif; ?>
});
</script>

<?php echo $OUTPUT->footer(); ?>

</body>
</html>
