<?php
// export_images.php
// Export all student‐submitted image files for one assignment into a ZIP archive.
// Place this file in: moodle/mod/assign/export_images.php

// 1) Remove execution limits & disable PHP output compression for large files
@set_time_limit(0);
@ini_set('memory_limit', '2048M');
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 'Off');

// 2) Bootstrap Moodle and its File API
require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

// 3) Validate parameters and permissions
//    - ‘id’ is the course_module ID for this assignment
$cmid = required_param('id', PARAM_INT);

//    Load course and module records, require login and the “view grades” capability
list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/assign:viewgrades', $context);

//    Prepare a return URL for any error redirects
$returnurl = new moodle_url('/mod/assign/view.php', ['id' => $cmid]);

// 4) Initialize a ZipArchive writing to a temp file
$fs      = get_file_storage();
$zip     = new ZipArchive();
$tmpfile = tempnam(sys_get_temp_dir(), 'assignimg_');

//    If ZipArchive isn't available or can't open, show an error and go back
if (!class_exists('ZipArchive') || $zip->open($tmpfile, ZipArchive::OVERWRITE) !== true) {
    redirect(
        $returnurl,
        get_string('zipunavailable', 'assign'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

// 5) Fetch all submissions for this assignment (drafts and submitted)
$submissions = $DB->get_records('assign_submission', ['assignment' => $cm->instance]);

//    If there are no submissions at all, clean up and warn the user
if (empty($submissions)) {
    $zip->close();
    unlink($tmpfile);
    redirect(
        $returnurl,
        get_string('nothingtodo', 'assign'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// 6) Prepare sanitized folder names and a counter
$coursefolder = preg_replace('/[^A-Za-z0-9_-]/', '_', $course->shortname);
$filecount    = 0;

// 7) Loop through each submission and add image files to the ZIP
foreach ($submissions as $sub) {
    // 7a) Build a safe student-name folder
    $user          = $DB->get_record('user', ['id' => $sub->userid], 'firstname,lastname');
    $studentfolder = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '_',
        "{$user->firstname}_{$user->lastname}"
    );

    // 7b) Retrieve all files in this submission’s “submission_files” area
    $files = $fs->get_area_files(
        $context->id,
        'assignsubmission_file',
        'submission_files',
        $sub->id,
        '',
        false
    );

    // 7c) Add each image/* file into the ZIP under Course/Student folders
    foreach ($files as $file) {
        if (strpos($file->get_mimetype(), 'image/') === 0) {
            $pathInZip = "{$coursefolder}/{$studentfolder}/" . $file->get_filename();
            $zip->addFromString($pathInZip, $file->get_content());
            $filecount++;
        }
    }
}

// 8) If no images were added, clean up and alert the user
if ($filecount === 0) {
    $zip->close();
    unlink($tmpfile);
    redirect(
        $returnurl,
        get_string('nothingtodo', 'assign'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

// 9) Finalize the ZIP archive
$zip->close();

// 10) Clear any PHP output buffers to avoid header issues
while (ob_get_level()) {
    ob_end_clean();
}

// 11) Send download headers and stream the ZIP
$filesize = filesize($tmpfile) ?: 0;

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="images_' . $coursefolder . '.zip"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $filesize);

//    Stream the file directly to the browser
readfile($tmpfile);

// 12) Delete the temp file and exit
unlink($tmpfile);
exit;
