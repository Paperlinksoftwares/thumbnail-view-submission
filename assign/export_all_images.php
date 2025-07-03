<?php
// export_all_images.php
// Export image submissions for one student, either for selected units (courses) or for all.

// 0) Clean output buffers
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 'Off');

// 1) Bootstrap Moodle & File API
require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

// 2) Params & permissions
$studentid         = required_param('studentid', PARAM_INT);
// we treat assignid[] as an array of COURSE IDs (units)
$selectedcourseids = optional_param_array('assignid', [], PARAM_INT);

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:viewparticipants', $syscontext);

// Prepare return URL on error
$returnurl = new moodle_url(
    '/grade/report/overview/studentgradeprogressadmin.php',
    ['userid' => $studentid]
);

// 3) Load student record for naming
$user = $DB->get_record('user',
    ['id' => $studentid],
    'firstname,lastname',
    IGNORE_MISSING
);
if (!$user) {
    redirect($returnurl,
        get_string('invaliduserid','assign'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
$studentfolder = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    "{$user->firstname}_{$user->lastname}"
);

// 4) Gather image files
$fs    = get_file_storage();
$files = [];

// 4a) If specific units selected, export only those COURSEs
if (!empty($selectedcourseids)) {
    foreach ($selectedcourseids as $courseid) {
        // validate course
        if (!$course = $DB->get_record('course',
            ['id' => $courseid],
            'shortname',
            IGNORE_MISSING
        )) {
            continue;
        }
        $coursefolder = preg_replace('/[^A-Za-z0-9_-]/','_',$course->shortname);

        // find ALL assign activities in that course
        $assigns = $DB->get_records('assign',['course'=>$courseid]);
        foreach ($assigns as $assign) {
            // get its context
            if (!$cm = get_coursemodule_from_instance('assign',
                $assign->id,
                $courseid,
                IGNORE_MISSING
            )) {
                continue;
            }
            $ctx = context_module::instance($cm->id);

            // fetch this student's submission
            $submission = $DB->get_record('assign_submission', [
                'assignment'=>$assign->id,
                'userid'    =>$studentid
            ], '*', IGNORE_MISSING);

            if (!$submission) {
                continue;
            }

            // grab files in submission_files area
            $stored = $fs->get_area_files(
                $ctx->id,
                'assignsubmission_file',
                'submission_files',
                $submission->id,
                '',
                false
            );
            foreach ($stored as $sf) {
                if (strpos($sf->get_mimetype(),'image/')===0) {
                    $relpath = "{$studentfolder}/{$coursefolder}/".$sf->get_filename();
                    $files[$relpath] = $sf;
                }
            }
        }
    }

// 4b) Otherwise export *all* units
} else {
    $courses = enrol_get_users_courses($studentid, true, 'id,shortname');
    foreach ($courses as $course) {
        $coursefolder = preg_replace('/[^A-Za-z0-9_-]/','_',$course->shortname);
        $assigns      = $DB->get_records('assign', ['course'=>$course->id]);
        foreach ($assigns as $assign) {
            if (!$cm = get_coursemodule_from_instance('assign',
                $assign->id,
                $course->id,
                IGNORE_MISSING
            )) {
                continue;
            }
            $ctx = context_module::instance($cm->id);
            $submission = $DB->get_record('assign_submission', [
                'assignment'=>$assign->id,
                'userid'    =>$studentid
            ], '*', IGNORE_MISSING);
            if (!$submission) {
                continue;
            }
            $stored = $fs->get_area_files(
                $ctx->id,
                'assignsubmission_file',
                'submission_files',
                $submission->id,
                '',
                false
            );
            foreach ($stored as $sf) {
                if (strpos($sf->get_mimetype(),'image/')===0) {
                    $relpath = "{$studentfolder}/{$coursefolder}/".$sf->get_filename();
                    $files[$relpath] = $sf;
                }
            }
        }
    }
}

// 5) Nothing found?
if (empty($files)) {
    redirect($returnurl,
        get_string('nothingtodo','assign'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// 6) Pack into ZIP
$tempzip = tempnam(sys_get_temp_dir(),'stuimg_');
$packer  = get_file_packer('application/zip');
try {
    $packer->archive_to_pathname($files,$tempzip);
} catch (Exception $e) {
    @unlink($tempzip);
    redirect($returnurl,
        get_string('zipunavailable','assign'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// 7) Stream it
while (ob_get_level()) {
    ob_end_clean();
}

$filename = !empty($selectedcourseids)
    ? "selected_units_images_{$studentfolder}.zip"
    : "all_units_images_{$studentfolder}.zip";
$filesize = filesize($tempzip)?:0;

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.basename($filename).'"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: '.$filesize);

readfile($tempzip);
unlink($tempzip);
exit;
