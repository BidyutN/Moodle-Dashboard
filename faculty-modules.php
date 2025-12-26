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
 * Faculty Module Completion Report for Moodle LMS
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
$facultiesPerPage = 5;

// Set up page
$PAGE->set_url(new moodle_url("/local/amizone/faculty-modules.php", [
    'city' => $selectedCity,
    'faculty' => $selectedFaculty,
    'page' => $page
]));
$PAGE->set_context(context_system::instance());
$title = "Faculty Module Completion Report" . (!empty($selectedCity) ? " - $selectedCity" : "");
$PAGE->set_title($title);
$PAGE->set_heading($title);

// Check permissions
if (!($USER->usertype == 'HOI' || $USER->usertype == 'Faculty' || is_siteadmin())) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('nopermissions', 'error', 'access this page'));
    echo $OUTPUT->footer();
    die;
}

// Apply HOI institute filter - HOI can only see their own institutes
$hoi_filter_f = '';
if ($USER->usertype == 'HOI') {
    $hoi_filter_f = " AND f.INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode') ";
}

// Apply Faculty user restriction - Faculty can only see their own data
$faculty_filter = '';
if ($USER->usertype == 'Faculty') {
    // Get faculty name based on user's idnumber
    $faculty_name = $DB->get_field_sql("
        SELECT TRIM(REPLACE(NAME, '\r', '')) 
        FROM my_faculty 
        WHERE ID = ?", [$USER->idnumber]);
    
    if ($faculty_name) {
        // Force filter to only show this faculty's data
        $selectedFaculty = $faculty_name;
        $faculty_filter = " AND TRIM(REPLACE(f.NAME, '\r', '')) = '" . $DB->sql_like_escape($faculty_name) . "' ";
    } else {
        // Faculty user but no faculty record found - show no data
        $selectedFaculty = 'NO_ACCESS';
        $faculty_filter = " AND 1=0 "; // This will return no results
    }
}

// Build the WHERE clause for city filter
$whereClause = "WHERE 1=1";
$params = array();

if (!empty($selectedCity)) {
    $whereClause .= " AND f.city = ?";
    $params[] = $selectedCity;
}

if (!empty($hoi_filter_f)) {
    $whereClause .= $hoi_filter_f;
}

if (!empty($faculty_filter)) {
    $whereClause .= $faculty_filter;
}

// Get all available faculties for the filter dropdown - APPLY HOI AND FACULTY FILTERS HERE
$facultyCondition = "WHERE 1=1";
$facultyParams = array();

if ($selectedCity) {
    $facultyCondition .= " AND city = ?";
    $facultyParams[] = $selectedCity;
}

if ($USER->usertype == 'HOI') {
    $facultyCondition .= " AND INSTITUTION in (SELECT iinstituteid FROM my_hoi WHERE sStaffCode='$USER->usercode')";
}

// Faculty users should only see themselves in the dropdown
if ($USER->usertype == 'Faculty') {
    if ($selectedFaculty && $selectedFaculty != 'NO_ACCESS') {
        $facultyCondition .= " AND TRIM(REPLACE(NAME, '\r', '')) = '" . $DB->sql_like_escape($selectedFaculty) . "'";
    } else {
        $facultyCondition .= " AND 1=0"; // No faculties if no valid faculty found
    }
}

$availableFaculties = $DB->get_fieldset_sql("
    SELECT DISTINCT TRIM(REPLACE(NAME, '\r', '')) as faculty_name 
    FROM my_faculty f
    $facultyCondition
    ORDER BY faculty_name", $facultyParams);

// Fetch ALL module completion data
$sql = "SELECT 
    CONCAT(f.ID, '_', m.id, '_', f.city) as unique_key,
    f.city,
    f.ID AS faculty_id, 
    TRIM(REPLACE(f.NAME, '\r', '')) AS faculty_name,
    m.name AS modulename,
    COUNT(DISTINCT cm.id) AS modulecount
FROM my_faculty f
JOIN my_moodle_course moc ON f.ID = moc.FACULTY
JOIN mdl_course_modules cm ON moc.MOODLE_COURSE = cm.course
JOIN mdl_modules m ON cm.module = m.id
$whereClause
GROUP BY f.city, f.ID, f.NAME, m.name, m.id
ORDER BY f.NAME, m.name";

$records = $DB->get_records_sql($sql, $params);

// Prepare data structure
$data = array();
$modules = array();
$allFacultiesWithData = array();

if ($records) {
    foreach ($records as $record) {
        $faculty = $record->faculty_name;
        $module = $record->modulename;
        $count = (int)$record->modulecount;
        
        if (!in_array($faculty, $allFacultiesWithData)) {
            $allFacultiesWithData[] = $faculty;
        }
        
        if (!in_array($module, $modules)) {
            $modules[] = $module;
        }
        
        if (!isset($data[$faculty])) {
            $data[$faculty] = array();
        }
        
        if (isset($data[$faculty][$module])) {
            $data[$faculty][$module] += $count;
        } else {
            $data[$faculty][$module] = $count;
        }
    }
}

sort($modules);

// Define consistent color mapping for modules - Based on your image
$moduleColors = array(
    'room' => '#7CB5EC',        // Blue for Room
    'imm' => '#F7A35C',         // Orange for IMM
    'masterie' => '#90ED7D',    // Green for masterie
    'assign' => '#7CB5EC',
    'book' => '#434348',
    'forum' => '#90ED7D',
    'lesson' => '#F7A35C',
    'page' => '#8085E9',
    'quiz' => '#F15C80',
    'url' => '#E4D354',
    'resource' => '#2B908F',
    'folder' => '#91E8E1',
    'workshop' => '#A8A8A8'
);

// HANDLE FACULTY FILTER
if (!empty($selectedFaculty)) {
    if (in_array($selectedFaculty, $availableFaculties)) {
        $facultiesForReport = array($selectedFaculty);
        $facultiesForCharts = array($selectedFaculty);
        $totalFaculties = 1;
        $totalPages = 1;
        $page = 1;
        $faculties = array($selectedFaculty);
    } else {
        $facultiesForReport = array();
        $facultiesForCharts = array();
        $totalFaculties = 0;
        $totalPages = 0;
        $faculties = array();
    }
} else {
    $facultiesForReport = $availableFaculties;
    sort($facultiesForReport);
    
    $totalFaculties = count($availableFaculties);
    $totalPages = ceil($totalFaculties / $facultiesPerPage);
    if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
    
    $startIndex = ($page - 1) * $facultiesPerPage;
    $faculties = array_slice($availableFaculties, $startIndex, $facultiesPerPage);
    $facultiesForCharts = $faculties;
}

// Prepare complete data for full report download
$fullReportData = array();
$allModulesForExport = $modules;

foreach ($facultiesForReport as $faculty) {
    $facultyData = array('faculty' => $faculty);
    $totalCount = 0;
    
    foreach ($allModulesForExport as $module) {
        $count = isset($data[$faculty][$module]) ? $data[$faculty][$module] : 0;
        $facultyData[$module] = $count;
        $totalCount += $count;
    }
    
    $facultyData['total'] = $totalCount;
    $fullReportData[] = $facultyData;
}

$fullReportJson = json_encode($fullReportData);
$allModulesJson = json_encode($allModulesForExport);
$moduleColorsJson = json_encode($moduleColors);

// Prepare series data for Highcharts
$series = array();
foreach ($modules as $module) {
    $moduleData = array();
    $hasNonZeroData = false;
    
    foreach ($faculties as $faculty) {
        $value = isset($data[$faculty][$module]) ? $data[$faculty][$module] : 0;
        if ($value > 0) {
            $hasNonZeroData = true;
        }
        $moduleData[] = $value;
    }
    
    if ($hasNonZeroData) {
        $seriesItem = array(
            'name' => $module,
            'data' => $moduleData
        );
        
        if (isset($moduleColors[$module])) {
            $seriesItem['color'] = $moduleColors[$module];
        }
        
        $series[] = $seriesItem;
    }
}

// Calculate faculty totals
$facultyTotals = array();
foreach ($facultiesForCharts as $faculty) {
    $total = 0;
    if (isset($data[$faculty])) {
        foreach ($data[$faculty] as $module => $count) {
            $total += $count;
        }
    }
    $facultyTotals[$faculty] = $total;
}

$facultyTotalsSeries = array();
foreach ($facultiesForCharts as $faculty) {
    $facultyTotalsSeries[] = isset($facultyTotals[$faculty]) ? $facultyTotals[$faculty] : 0;
}

// Module Distribution
$moduleDistribution = array();

if (!empty($selectedFaculty)) {
    if (isset($data[$selectedFaculty])) {
        foreach ($data[$selectedFaculty] as $module => $count) {
            if ($count > 0) {
                $moduleDistribution[$module] = $count;
            }
        }
    }
} else {
    foreach ($data as $faculty => $moduleData) {
        foreach ($moduleData as $module => $count) {
            if (!isset($moduleDistribution[$module])) {
                $moduleDistribution[$module] = 0;
            }
            $moduleDistribution[$module] += $count;
        }
    }
}

$moduleDistributionSeries = array();
foreach ($moduleDistribution as $module => $count) {
    if ($count > 0) {
        $pieSlice = array(
            'name' => $module,
            'y' => $count
        );
        
        if (isset($moduleColors[$module])) {
            $pieSlice['color'] = $moduleColors[$module];
        }
        
        $moduleDistributionSeries[] = $pieSlice;
    }
}

usort($moduleDistributionSeries, function($a, $b) {
    return $b['y'] - $a['y'];
});

$facultyTotalsJson = json_encode($facultyTotalsSeries);
$moduleDistributionJson = json_encode($moduleDistributionSeries);

echo $OUTPUT->header();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Module Completion Report<?php echo !empty($selectedCity) ? ' - ' . htmlspecialchars($selectedCity) : ''; ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
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

    .header-container {
        margin-bottom: 2rem;
    }

    .alert-success {
        border-left: 4px solid #28a745;
    }
    
    body {
        background-color: #f8f9fa;
        padding: 20px 0;
    }
    
    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        border: 1px solid rgba(0, 0, 0, 0.125);
        margin: 20px 0;
    }

    .chart-container {
        padding: 30px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        margin: 20px 0;
    }

    .main-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 20px;
    }

    .header-section {
        background: white;
        padding: 20px 30px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 30px;
    }
    
    .chart-filters {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }
    
    .chart-filter-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .chart-filter-group label {
        font-weight: bold;
        font-size: 14px;
    }
    
    .chart-filters button {
        padding: 8px 15px;
        background-color: #007BFF;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
        margin-left: 10px;
    }
    
    .chart-filters button:hover {
        background-color: #0056b3;
    }
    
    .chart-filters button.clear-btn {
        background-color: #6c757d;
    }
    
    .chart-filters button.clear-btn:hover {
        background-color: #dc3545;
    }
    
    .chart-filter-group select {
        padding: 6px 12px;
        font-size: 14px;
        border: 1px solid #ddd;
        border-radius: 4px;
        min-width: 180px;
    }
    
    .chart-row {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
    }
    .chart-col {
        flex: 1;
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        padding: 15px;
    }
    .chart-col h3 {
        text-align: center;
        margin-bottom: 15px;
        color: #333;
    }
    
    .download-success {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 1050;
    }

    .permission-info {
        background-color: #e7f3ff;
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
        font-size: 14px;
        border-left: 4px solid #007BFF;
    }

    .faculty-restricted-view {
        background-color: #fff3cd;
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
        font-size: 14px;
        border-left: 4px solid #ffc107;
    }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="header-section">
            <div class="d-flex align-items-center justify-content-between">
                <a href="reportchartdashboard.php<?php echo !empty($selectedCity) ? '?city=' . urlencode($selectedCity) : ''; ?>" class="btn btn-success">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <h2 class="text-center flex-grow-1 mb-0">Module Completion Report<?php echo !empty($selectedCity) ? ' - ' . htmlspecialchars($selectedCity) : ''; ?></h2>
                <?php if (!empty($fullReportData) && $USER->usertype != 'Student'): ?>
                <div class="dropdown">
                    <button class="download-btn dropdown-toggle" type="button" id="downloadDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa fa-download"></i> Download Full Report
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="downloadDropdown">
                        <li><a class="dropdown-item" href="#" onclick="downloadFullReport('csv')">
                            <i class="fa fa-file-text"></i> Download as CSV
                        </a></li>
                        <li><a class="dropdown-item" href="#" onclick="downloadFullReport('excel')">
                            <i class="fa fa-file-excel"></i> Download as Excel
                        </a></li>
                        <li><a class="dropdown-item" href="#" onclick="downloadFullReport('json')">
                            <i class="fa fa-code"></i> Download as JSON
                        </a></li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="downloadSuccessMessage" class="alert alert-success download-success" role="alert" style="display: none;">
            <i class="fa fa-check-circle"></i> Download complete! Saved as: <strong id="downloadFilename"></strong>
        </div>

        <!-- <?php if ($USER->usertype == 'Faculty'): ?>
        <div class="faculty-restricted-view">
            <i class="fas fa-user-shield"></i> 
            <strong>Faculty View:</strong> You are viewing data only for your faculty (<?php echo htmlspecialchars($selectedFaculty); ?>).
        </div>
        <?php endif; ?> -->

        <?php if ($USER->usertype == 'HOI'): ?>
        <div class="permission-info">
            <i class="fas fa-info-circle"></i> 
            <strong>HOI View:</strong> You are viewing data only for faculties under your institutes. 
            Showing <?php echo count($availableFaculties); ?> faculty(s) in the filter.
        </div>
        <?php endif; ?>

        <?php if ($USER->usertype != 'Faculty'): ?>
        <form id="facultyFilterForm" method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <div class="chart-filters">
                
                    <div class="chart-filter-group">
                        <label for="facultyFilterBarChart">Filter by Faculty:</label>
                        <select id="facultyFilterBarChart" name="faculty" class="select2">
                            <option value="">All Faculty</option>
                            <?php foreach($availableFaculties as $faculty): ?>
                                <option value="<?php echo htmlspecialchars($faculty); ?>" <?php echo ($selectedFaculty === $faculty) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($faculty); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if(!empty($selectedCity)): ?>
                            <input type="hidden" name="city" value="<?php echo htmlspecialchars($selectedCity); ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <button type="submit">Apply Filter</button>
                        <?php if(!empty($selectedFaculty)): ?>
                            <button type="button" class="clear-btn" onclick="clearFacultyFilter()">Clear Filter</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        

        <?php if (empty($availableFaculties)): ?>
            <div class="alert alert-warning text-center" role="alert">
                <?php if ($USER->usertype == 'HOI'): ?>
                    No faculty found under your institutes<?php echo !empty($selectedCity) ? ' in ' . htmlspecialchars($selectedCity) : ''; ?>.
                <?php elseif ($USER->usertype == 'Faculty'): ?>
                    No faculty data found for your account.
                <?php else: ?>
                    No faculty found<?php echo !empty($selectedCity) ? ' in ' . htmlspecialchars($selectedCity) : ''; ?>.
                <?php endif; ?>
            </div>
        <?php elseif (!empty($selectedFaculty) && empty($faculties)): ?>
            <div class="alert alert-warning text-center" role="alert">
                The selected faculty "<?php echo htmlspecialchars($selectedFaculty); ?>" was not found or you don't have permission to view it.
            </div>
        <?php else: ?>

            <div class="chart-row">
                <div class="chart-col">
                    <h3>Total Module Count by Faculty</h3>
                    <?php if (empty($facultyTotalsSeries) || array_sum($facultyTotalsSeries) == 0): ?>
                        <div class="alert alert-info text-center" role="alert">
                            No module data available<?php echo !empty($selectedFaculty) ? ' for ' . htmlspecialchars($selectedFaculty) : ' for current page'; ?>.
                        </div>
                    <?php else: ?>
                        <div id="facultyTotalsChartContainer" style="height: 400px;"></div>
                    <?php endif; ?>
                </div>
                <div class="chart-col">
                    <h3><?php echo !empty($selectedFaculty) ? 'Module Distribution for Selected Faculty' : 'Overall Module Distribution'; ?></h3>
                    <?php if (empty($moduleDistributionSeries)): ?>
                        <div class="alert alert-info text-center" role="alert">
                            No module distribution data available<?php echo !empty($selectedFaculty) ? ' for ' . htmlspecialchars($selectedFaculty) : ''; ?>.
                        </div>
                    <?php else: ?>
                        <div id="moduleDistributionChartContainer" style="height: 400px;"></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chart-container">
                <h2 class="text-center mb-4">Module Completion by Faculty and Module Type</h2>
                <?php if (empty($modules)): ?>
                    <div class="alert alert-info text-center" role="alert">
                        No module completion data found<?php echo !empty($selectedFaculty) ? ' for ' . htmlspecialchars($selectedFaculty) : ''; ?>.
                    </div>
                <?php elseif (empty($series)): ?>
                    <div class="alert alert-info text-center" role="alert">
                        No module data available for the selected faculty/page.
                    </div>
                <?php else: ?>
                    <div id="facultyModuleChartContainer" style="height: 400px;"></div>
                <?php endif; ?>
                
                <?php if ($totalPages > 1 && empty($selectedFaculty) && $USER->usertype != 'Faculty'): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($selectedCity) ? '&city=' . urlencode($selectedCity) : ''; ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link"><i class="fas fa-chevron-left"></i> Previous</span>
                                </li>
                            <?php endif; ?>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?page=1' . (!empty($selectedCity) ? '&city=' . urlencode($selectedCity) : '') . '">1</a></li>';
                                if ($startPage > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }

                            for ($i = $startPage; $i <= $endPage; $i++) {
                                if ($i == $page) {
                                    echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
                                } else {
                                    echo '<li class="page-item"><a class="page-link" href="?page=' . $i . (!empty($selectedCity) ? '&city=' . urlencode($selectedCity) : '') . '">' . $i . '</a></li>';
                                }
                            }

                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . (!empty($selectedCity) ? '&city=' . urlencode($selectedCity) : '') . '">' . $totalPages . '</a></li>';
                            }
                            ?>

                            <?php if ($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($selectedCity) ? '&city=' . urlencode($selectedCity) : ''; ?>">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">Next <i class="fas fa-chevron-right"></i></span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="https://code.highcharts.com/modules/exporting.js"></script>
    <script src="https://code.highcharts.com/modules/export-data.js"></script>
    <script src="https://code.highcharts.com/modules/accessibility.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
    
    const fullReportData = <?php echo $fullReportJson; ?>;
    const allModules = <?php echo $allModulesJson; ?>;
    const moduleColors = <?php echo $moduleColorsJson; ?>;

    function showDownloadSuccess(format, filename) {
        const messageBox = document.getElementById('downloadSuccessMessage');
        const filenameSpan = document.getElementById('downloadFilename');
        
        filenameSpan.textContent = filename;
        messageBox.style.display = 'block';
        
        setTimeout(() => {
            messageBox.style.display = 'none';
        }, 5000);
    }
    
    function downloadFullReport(format = 'csv') {
        const now = new Date();
        const timestamp = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0') + '_' + String(now.getHours()).padStart(2, '0') + '-' + String(now.getMinutes()).padStart(2, '0');
        const cityPart = <?php echo json_encode(!empty($selectedCity) ? '_' . $selectedCity : ''); ?>;
        const facultyPart = <?php echo json_encode(!empty($selectedFaculty) ? '_' . $selectedFaculty : ''); ?>;
        let content, mimeType, filename;

        switch(format) {
            case 'csv':
                content = generateCSV();
                mimeType = 'text/csv;charset=utf-8;';
                filename = `Faculty_Module_Completion_Report${cityPart}${facultyPart}_${timestamp}.csv`;
                break;
            case 'excel':
                content = "\uFEFF" + generateCSV();
                mimeType = 'text/csv;charset=utf-8;';
                filename = `Faculty_Module_Completion_Report${cityPart}${facultyPart}_${timestamp}.csv`;
                break;
            case 'json':
                content = JSON.stringify({
                    report_title: `Faculty Module Completion Report${cityPart}${facultyPart}`,
                    generated_at: now.toISOString(),
                    modules: allModules,
                    data: fullReportData
                }, null, 2);
                mimeType = 'application/json;charset=utf-8;';
                filename = `Faculty_Module_Completion_Report${cityPart}${facultyPart}_${timestamp}.json`;
                break;
            default:
                content = generateCSV();
                mimeType = 'text/csv;charset=utf-8;';
                filename = `Faculty_Module_Completion_Report${cityPart}${facultyPart}_${timestamp}.csv`;
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
        let csvContent = "Faculty";
        allModules.forEach(module => {
            csvContent += "," + JSON.stringify(module.replace(/"/g, '""'));
        });
        csvContent += ",Total\n";

        fullReportData.forEach(row => {
            let rowData = JSON.stringify(row.faculty.replace(/"/g, '""'));
            allModules.forEach(module => {
                const count = row[module] || 0;
                rowData += "," + count;
            });
            rowData += "," + row.total;
            csvContent += rowData + "\n";
        });
        return csvContent;
    }
    
    function clearFacultyFilter() {
        let url = '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>';
        const params = new URLSearchParams(window.location.search);
        params.delete('faculty');
        params.delete('page');
        
        const city = params.get('city');
        if (city) {
            url += '?city=' + encodeURIComponent(city);
        }
        
        window.location.href = url;
    }

    const categories = <?php echo json_encode($faculties); ?>;
    const moduleSeries = <?php echo json_encode($series); ?>;

    <?php if (!empty($series)): ?>
    Highcharts.chart('facultyModuleChartContainer', {
        chart: {
            type: 'bar',
            style: {
                fontFamily: 'Arial, sans-serif'
            }
        },
        title: {
            text: null
        },
        subtitle: {
            text: 'Counts represent the number of course modules associated with the faculty.'
        },
        xAxis: {
            categories: categories,
            crosshair: true,
            labels: {
                style: {
                    fontSize: '11px',
                    color: '#333333'
                }
            },
            lineColor: '#E0E0E0',
            tickColor: '#E0E0E0'
        },
        yAxis: {
            min: 0,
            title: {
                text: 'Module Count',
                style: {
                    fontSize: '12px',
                    color: '#333333'
                }
            },
            gridLineColor: '#F0F0F0',
            labels: {
                style: {
                    fontSize: '11px',
                    color: '#333333'
                }
            },
            stackLabels: {
                enabled: true,
                style: {
                    fontWeight: 'bold',
                    color: (
                        Highcharts.defaultOptions.title.style &&
                        Highcharts.defaultOptions.title.style.color
                    ) || 'gray',
                    textOutline: '1px contrast'
                }
            }
        },
        tooltip: {
            pointFormat: '{series.name}: {point.y}<br/>Total: {point.stackTotal}'
        },
        plotOptions: {
            series: {
                stacking: 'normal',
                dataLabels: {
                    enabled: true,
                    formatter: function() {
                        return (this.y !== null && this.y > 0) ? this.y : '';
                    },
                    style: {
                        fontSize: '10px',
                        fontWeight: 'bold',
                        textOutline: '1px contrast'
                    },
                    verticalAlign: 'middle',
                    crop: false,
                    overflow: 'none'
                }
            },
            bar: {
                groupPadding: 0.1,
                pointPadding: 0.1
            }
        },
        legend: {
            align: 'center',
            verticalAlign: 'bottom',
            layout: 'horizontal',
            itemStyle: {
                fontSize: '11px',
                color: '#333333'
            },
            backgroundColor: '#ffffff',
            borderColor: '#E0E0E0',
            borderWidth: 1,
            borderRadius: 3,
            padding: 10,
            itemMarginTop: 5,
            itemMarginBottom: 5,
            symbolHeight: 10,
            symbolWidth: 10,
            symbolRadius: 2
        },
        credits: {
            enabled: false
        },
        exporting: {
            enabled: true,
            buttons: {
                contextButton: {
                    menuItems: ['viewFullscreen', 'separator', 'downloadPNG', 'downloadJPEG', 'downloadPDF', 'downloadSVG']
                }
            }
        },
        series: moduleSeries
    });
    <?php endif; ?>
    
    const facultyTotalsSeriesData = <?php echo $facultyTotalsJson; ?>;

    <?php if (!empty($facultyTotalsSeries) && array_sum($facultyTotalsSeries) > 0): ?>
    Highcharts.chart('facultyTotalsChartContainer', {
        chart: {
            type: 'column',
            style: {
                fontFamily: 'Arial, sans-serif'
            }
        },
        title: {
            text: null
        },
        subtitle: {
            text: 'Total number of modules by faculty'
        },
        xAxis: {
            categories: categories,
            title: {
                text: 'Faculty'
            },
            labels: {
                rotation: -45,
                style: {
                    fontSize: '11px'
                }
            }
        },
        yAxis: {
            min: 0,
            title: {
                text: 'Total Module Count'
            }
        },
        tooltip: {
            pointFormat: 'Total Modules: <b>{point.y}</b>'
        },
        plotOptions: {
            column: {
                dataLabels: {
                    enabled: true,
                    formatter: function() {
                        return (this.y !== null && this.y > 0) ? this.y : '';
                    }
                }
            }
        },
        legend: {
            enabled: false
        },
        credits: {
            enabled: false
        },
        exporting: {
            enabled: true,
            buttons: {
                contextButton: {
                    menuItems: ['viewFullscreen', 'separator', 'downloadPNG', 'downloadJPEG', 'downloadPDF', 'downloadSVG']
                }
            }
        },
        series: [{
            name: 'Total Modules',
            data: facultyTotalsSeriesData,
            color: '#17a2b8'
        }]
    });
    <?php endif; ?>

    const moduleDistributionSeriesData = <?php echo $moduleDistributionJson; ?>;

    <?php if (!empty($moduleDistributionSeries)): ?>
    Highcharts.chart('moduleDistributionChartContainer', {
        chart: {
            plotBackgroundColor: null,
            plotBorderWidth: null,
            plotShadow: false,
            type: 'pie',
            style: {
                fontFamily: 'Arial, sans-serif'
            }
        },
        title: {
            text: null
        },
        subtitle: {
            text: <?php echo json_encode(!empty($selectedFaculty) ? 'Distribution for ' . $selectedFaculty : 'Distribution of modules across all faculties'); ?>
        },
        tooltip: {
            pointFormat: '{series.name}: <b>{point.y}</b> ({point.percentage:.1f}%)'
        },
        accessibility: {
            point: {
                valueSuffix: '%'
            }
        },
        plotOptions: {
            pie: {
                allowPointSelect: true,
                cursor: 'pointer',
                dataLabels: {
                    enabled: true,
                    format: '<b>{point.name}</b>: {point.percentage:.1f}%',
                    style: {
                        fontSize: '11px'
                    }
                },
                showInLegend: true
            }
        },
        legend: {
            align: 'right',
            verticalAlign: 'middle',
            layout: 'vertical',
            itemStyle: {
                fontSize: '11px',
                color: '#333333'
            }
        },
        credits: {
            enabled: false
        },
        exporting: {
            enabled: true,
            buttons: {
                contextButton: {
                    menuItems: ['viewFullscreen', 'separator', 'downloadPNG', 'downloadJPEG', 'downloadPDF', 'downloadSVG']
                }
            }
        },
        series: [{
            name: 'Module Count',
            colorByPoint: true,
            data: moduleDistributionSeriesData
        }]
    });
    <?php endif; ?>

    <?php if ($USER->usertype != 'Faculty'): ?>
    $(document).ready(function() {
        $('#facultyFilterBarChart').select2({
            placeholder: "Select a faculty",
            allowClear: true
        });
    });
    <?php endif; ?>
    </script>

</body>
</html>

<?php echo $OUTPUT->footer(); ?>
