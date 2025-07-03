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
 * This file is the entry point to the assign module. All pages are rendered from here
 *
 * @package   mod_assign
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

$id = required_param('id', PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'assign');

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

require_capability('mod/assign:view', $context);

$assign = new assign($context, $cm, $course);
$urlparams = array(
    'id' => $id,
    'action' => optional_param('action', '', PARAM_ALPHA),
    'rownum' => optional_param('rownum', 0, PARAM_INT),
    'useridlistid' => optional_param('useridlistid', $assign->get_useridlist_key_id(), PARAM_ALPHANUM)
);

$url = new moodle_url('/mod/assign/view.php', $urlparams);
$PAGE->set_url($url);

// Update module completion status.
$assign->set_module_viewed();

// Apply overrides.
$assign->update_effective_access($USER->id);

// Get the submission for the current user (false means we do not want to edit)
$submission = $assign->get_user_submission($USER->id, false);

// If the user has submitted the assignment
if ($submission && $submission->status == ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
    // Get the context where the submission is stored
    $context = context_module::instance($cm->id);

    // Get the file storage instance
    $fs = get_file_storage();

    // Retrieve all files submitted in the "assignsubmission_file" file area
    $files = $fs->get_area_files($context->id, 'mod_assign', 'submission_files', $submission->id, 'id', false);

    // Display the files in a gallery view
    echo '<div class="photo-gallery">';
    foreach ($files as $file) {
        // Get the file URL
        $fileurl = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(),
                                                  $file->get_itemid(), $file->get_filepath(), $file->get_filename());

        echo '<div class="photo-item">';
        echo '<img src="' . $fileurl . '" alt="' . $file->get_filename() . '" />';
        echo '<input type="checkbox" name="delete[]" value="' . $file->get_id() . '" />';
        echo '</div>';
    }
    echo '</div>';
}

// Add delete button
echo '<button id="deleteSelectedBtn">Delete Selected Photos</button>';

// JavaScript to handle the deletion of selected photos
echo '<script>
document.getElementById("deleteSelectedBtn").addEventListener("click", function() {
    // Collect selected image IDs
    var selectedImages = [];
    var checkboxes = document.querySelectorAll("input[name=\"delete[]\"]:checked");
    checkboxes.forEach(function(checkbox) {
        selectedImages.push(checkbox.value);
    });

    // Send AJAX request to delete selected images
    if (selectedImages.length > 0) {
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "delete-photos.php", true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onload = function() {
            if (xhr.status === 200) {
                alert("Photos deleted successfully!");
                location.reload(); // Reload page after deletion
            } else {
                alert("Error deleting photos.");
            }
        };
        xhr.send("photos=" + JSON.stringify(selectedImages));
    } else {
        alert("Please select photos to delete.");
    }
});
</script>';

// CSS for the gallery layout
echo '<style>
.photo-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}
.photo-item {
    text-align: center;
}
.photo-item img {
    width: 100%;
    height: auto;
    border-radius: 5px;
}
</style>';

// Get the assign class to render the rest of the page
echo $assign->view(optional_param('action', '', PARAM_ALPHA));
?>
