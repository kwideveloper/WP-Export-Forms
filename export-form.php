<?php
/**
 * Plugin Name: Export Fluent Forms
 * Plugin URI:
 * Description: Plugin to export combined form data with dynamic date filtering and proper formatting of response JSON.
 * Version: 1.0
 * Author: Andres D.
 * Author URI:
 * License: GPL2
 */

// Function to handle form export based on selected date range and forms
add_action('admin_post_export_forms_custom_month', function() {
    if (isset($_POST['selected_month']) && isset($_POST['selected_year'])) {
        $month = intval($_POST['selected_month']);
        $year = intval($_POST['selected_year']);
        $form_ids = isset($_POST['selected_forms']) ? array_map('intval', $_POST['selected_forms']) : [10, 9, 3]; // Default forms if none are selected
        export_forms_by_date_range($month, $year, $form_ids);
    } else {
        wp_die('Please select a valid option.');
    }
});

function export_forms_by_date_range($month, $year, $form_ids) {
    global $wpdb;

    // Calculate date range based on selected month and year
    $start_date = date('Y-m-d', strtotime("$year-$month-01")); // First day of the selected month
    $end_date = date('Y-m-t', strtotime("$year-$month-01"));   // Last day of the selected month

    // Format date range for the new column
    $month_name = date('F', strtotime("$year-$month-01")); // Full month name in English
    $date_range = strtolower($month_name) . ' ' . date('j', strtotime($start_date)) . '-' . date('j', strtotime($end_date));

    $combined_data = [];

    // Fetch data for each form within the date range
    foreach ($form_ids as $form_id) {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fluentform_submissions 
                 WHERE form_id = %d AND created_at BETWEEN %s AND %s",
                $form_id, $start_date, $end_date
            ),
            ARRAY_A
        );
        $combined_data = array_merge($combined_data, $results);
    }

    // Check if there is any data to export
    if (empty($combined_data)) {
        wp_die('No data available for export within the specified date range.');
    }

    // Map and format data to match target-export.csv structure
    $formatted_data = [];
    foreach ($combined_data as $entry) {
        $response_data = isset($entry['response']) ? json_decode($entry['response'], true) : [];
        $form_source = isset($entry['source_url']) ? basename(parse_url($entry['source_url'], PHP_URL_PATH)) : '';
		
		// Fetch the form name using form_id
		$form_name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
				$entry['form_id']
			)
		);

        // Map data from the response JSON to the CSV columns
        $formatted_data[] = [
            'Date Range' => $date_range,
            'Form Name' => $form_name, // Always use the form name from the database
            'Email' => $response_data['email'] ?? '',
            'Name' => isset($response_data['names']) ? $response_data['names']['first_name'] . ' ' . $response_data['names']['last_name'] : '',
            'Address' => $response_data['home_address'] ?? '',
            'City' => $response_data['home_city'] ?? '',
            'State' => $response_data['home_state'] ?? '',
            'Zip code' => $response_data['home_postcode'] ?? '',
            'Personal Number' => $response_data['personal_number'] ?? '',
            'Company/School Name' => $response_data['input_text'] ?? '',
            'W Address' => $response_data['work_address'] ?? '',
            'W City' => $response_data['work_city'] ?? '',
            'W State' => $response_data['work_state'] ?? '',
            'W Zip code' => $response_data['work_postcode'] ?? '',
            'Work Number' => $response_data['work_number'] ?? '',
            'Pledge to try by the end of the month' => isset($response_data['checkbox']) ? implode(', ', $response_data['checkbox']) : '',
            'Other Pledge to try' => $response_data['input_text_1'] ?? '',
			'Carpool' => isset($response_data['checkbox_1']) ? implode(', ', $response_data['checkbox_1']) : '',
            'Telework Resources' => isset($response_data['checkbox_7']) ? implode(', ', $response_data['checkbox_7']) : '',
            'Bike' => isset($response_data['checkbox_2']) ? implode(', ', $response_data['checkbox_2']) : '',
            'Transit' => isset($response_data['checkbox_8']) ? implode(', ', $response_data['checkbox_8']) : '',
            'Vanpool' => isset($response_data['checkbox_9']) ? implode(', ', $response_data['checkbox_9']) : '',
            'Guaranteed Ride Home' => isset($response_data['checkbox_10']) ? implode(', ', $response_data['checkbox_10']) : '',
            'I start work at' => $response_data['datetime'] ?? '',
            'I finish work at' => $response_data['datetime_1'] ?? '',
            'Typical way to commute' => $response_data['dropdown'] ?? '',
            'Other way to commute' => $response_data['input_text_1'] ?? '',
            'HM Days do you telework' => $response_data['dropdown_4'] ?? '',
            'HM miles is your one-way commute' => $response_data['numeric_field'] ?? '',
            'HM days a week do you drive alone' => $response_data['dropdown_3'] ?? '',
            'Opt in Form' => isset($response_data['checkbox_4'][0]) && strtolower($response_data['checkbox_4'][0]) === 'yes' ? 'Yes' : '',
			'Event/Fair Name' => $response_data['event_name'] ?? '',
            'Event/Fair Date' => $response_data['event_date'] ?? ''
        ];
    }

    // Create CSV file
    $filename = 'forms_export_' . $year . '_' . $month . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, array_keys($formatted_data[0]));
    foreach ($formatted_data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}


// Add a button to the admin menu
add_action('admin_menu', function() {
    add_menu_page(
        'Export Forms',
        'Export Forms',
        'manage_options',
        'export_forms_page',
        'export_forms_page',
        'dashicons-download',
        4
    );
});

// Function to generate the admin page with the enhanced UI
function export_forms_page() {
    global $wpdb;

    // Fetch all forms from Fluent Forms
    $forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    $default_selected_forms = [10, 9, 3]; // Default selected forms

    echo '
       <style>
    .wp-core-ui select[multiple] option {
        padding: 10px;
        border: 2px solid lightgray;
        margin: 5px;
		border-radius: 4px;
    }
	
	 .wp-core-ui select[multiple] option:hover {
        background: #d3d3d369;
    }
	
	#available_forms option:checked {
		background: transparent;
	}
	
	.wp-core-ui select[multiple] option:checked, #selected_forms option {
		background: #90ee907d;
	}
	
	#export-current-month {
		margin-right: 10px;
	}

      
    </style>
    ';
    echo '<div class="wrap">';
    echo '<h1>Export Forms</h1>';
    echo '<p>Select the month, year, and forms to export the combined forms data.</p>';
    echo '<form method="POST" action="' . admin_url('admin-post.php?action=export_forms_custom_month') . '">';
    echo '<div style="display: flex; gap: 20px;">';

    // Left column: Available forms
    echo '<div style="flex: 1;">';
    echo '<h3>Available Forms</h3>';
    echo '<select id="available_forms" multiple style="width: 100%; height: 300px;">';
    foreach ($forms as $form) {
        if (!in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . $form['id'] . '">' . $form['title'] . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';

    // Right column: Selected forms
    echo '<div style="flex: 1;">';
    echo '<h3>Selected Forms</h3>';
    echo '<select name="selected_forms[]" id="selected_forms" multiple required style="width: 100%; height: 300px;">';
    foreach ($forms as $form) {
        if (in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . $form['id'] . '" selected>' . $form['title'] . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';

    // Month and Year selectors
    echo '<br><label for="selected_month">Month:</label>';
    echo '<select name="selected_month" id="selected_month" required>';
    for ($month = 1; $month <= 12; $month++) {
        echo '<option value="' . $month . '">' . date('F', strtotime("2000-$month-01")) . '</option>';
    }
    echo '</select>';
	echo '<label for="selected_year" style="margin-left: 10px;">Year:</label>';
	$current_year = date("Y");
	$start_year = 2024;

	echo '<select name="selected_year" id="selected_year" required>'; // Cambiamos el atributo "name" a "selected_year"
	for ($year = $start_year; $year <= $current_year; $year++) {
		// Mark the current year as selected
		$selected = ($year == $current_year) ? 'selected' : '';
		echo "<option value=\"$year\" $selected>$year</option>";
	}
	echo '</select>';
    echo '<br><br><button type="submit" id="export-button" class="button button-primary">Export Selected Month</button>';
    
    // Quick Export Buttons
    echo '<br><br>';
    echo '<h3>Quick Export:</h3>';
    echo '<button type="button" class="button button-secondary" id="export-current-month">Export Current Month</button>';
    echo '<button type="button" class="button button-secondary" id="export-previous-month">Export Previous Month</button>';
    echo '</form>';
    echo '</div>';

    // JavaScript for ensuring all options remain selected and quick export functionality
    echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    // Function to keep all options selected
    function keepAllSelected(selectId) {
        const select = document.getElementById(selectId);
        if (select) {
            Array.from(select.options).forEach(option => option.selected = true);
        }
    }

    // Move options between lists
    function moveOptions(sourceId, targetId) {
        const source = document.getElementById(sourceId);
        const target = document.getElementById(targetId);
        if (source && target) {
            Array.from(source.selectedOptions).forEach(option => target.appendChild(option));
        }
    }

    // Double-click functionality for moving options
    const availableForms = document.getElementById("available_forms");
    const selectedForms = document.getElementById("selected_forms");

    if (availableForms) {
        availableForms.addEventListener("dblclick", function () {
            moveOptions("available_forms", "selected_forms");
        });
    }

    if (selectedForms) {
        selectedForms.addEventListener("dblclick", function () {
            moveOptions("selected_forms", "available_forms");
        });

        // Focus out: Select all options in "selected_forms"
        selectedForms.addEventListener("focusout", function () {
            keepAllSelected("selected_forms");
        });
    }

    // Button functionality for moving options
    const addFormButton = document.getElementById("add_form");
    const removeFormButton = document.getElementById("remove_form");

    if (addFormButton) {
        addFormButton.addEventListener("click", function () {
            moveOptions("available_forms", "selected_forms");
        });
    }

    if (removeFormButton) {
        removeFormButton.addEventListener("click", function () {
            moveOptions("selected_forms", "available_forms");
        });
    }

    // Ensure all options are selected when the "Export Selected Month" button is clicked
    const exportButton = document.querySelector("#export-button");
    if (exportButton) {
        exportButton.addEventListener("click", function () {
            keepAllSelected("selected_forms");
        });
    }

    // Quick Export Buttons
    const exportCurrentMonthButton = document.getElementById("export-current-month");
    const exportPreviousMonthButton = document.getElementById("export-previous-month");

    if (exportCurrentMonthButton) {
        exportCurrentMonthButton.addEventListener("click", function () {
            const currentDate = new Date();
            const currentMonth = currentDate.getMonth() + 1; // Months are zero-indexed
            const currentYear = currentDate.getFullYear();

            const form = document.createElement("form");
            form.method = "POST";
            form.action = "' . admin_url('admin-post.php?action=export_forms_custom_month') . '";

            const monthInput = document.createElement("input");
            monthInput.type = "hidden";
            monthInput.name = "selected_month";
            monthInput.value = currentMonth;

            const yearInput = document.createElement("input");
            yearInput.type = "hidden";
            yearInput.name = "selected_year";
            yearInput.value = currentYear;

            const formsInput = document.createElement("input");
            formsInput.type = "hidden";
            formsInput.name = "selected_forms[]";
            Array.from(document.getElementById("selected_forms").options).forEach(option => {
                if (option.selected) {
                    const clone = formsInput.cloneNode();
                    clone.value = option.value;
                    form.appendChild(clone);
                }
            });

            form.appendChild(monthInput);
            form.appendChild(yearInput);
            document.body.appendChild(form);
            form.submit();
        });
    }

    if (exportPreviousMonthButton) {
        exportPreviousMonthButton.addEventListener("click", function () {
            const currentDate = new Date();
            const previousMonthDate = new Date(currentDate.getFullYear(), currentDate.getMonth() - 1, 1);
            const previousMonth = previousMonthDate.getMonth() + 1; // Months are zero-indexed
            const previousYear = previousMonthDate.getFullYear();

            const form = document.createElement("form");
            form.method = "POST";
            form.action = "' . admin_url('admin-post.php?action=export_forms_custom_month') . '";

            const monthInput = document.createElement("input");
            monthInput.type = "hidden";
            monthInput.name = "selected_month";
            monthInput.value = previousMonth;

            const yearInput = document.createElement("input");
            yearInput.type = "hidden";
            yearInput.name = "selected_year";
            yearInput.value = previousYear;

            const formsInput = document.createElement("input");
            formsInput.type = "hidden";
            formsInput.name = "selected_forms[]";
            Array.from(document.getElementById("selected_forms").options).forEach(option => {
                if (option.selected) {
                    const clone = formsInput.cloneNode();
                    clone.value = option.value;
                    form.appendChild(clone);
                }
            });

            form.appendChild(monthInput);
            form.appendChild(yearInput);
            document.body.appendChild(form);
            form.submit();
        });
    }
});
    </script>';
}