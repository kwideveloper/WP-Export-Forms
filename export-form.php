<?php
/**
 * Plugin Name: Export Fluent Forms
 * Description: Export combined form data with dynamic date filtering and console debug for recent submissions.
 * Version: 1.1
 * Author: Andres D.
 * License: GPL2
 */

// ==============================
// Export handler
// ==============================
add_action('admin_post_export_forms_custom_month', function() {
    if (isset($_POST['selected_month'], $_POST['selected_year'])) {
        $month = intval($_POST['selected_month']);
        $year = intval($_POST['selected_year']);
        $form_ids = isset($_POST['selected_forms']) ? array_map('intval', (array)$_POST['selected_forms']) : [10, 9, 3];
        export_forms_by_date_range($month, $year, $form_ids);
    } else {
        wp_die('Please select a valid option.');
    }
});

// ==============================
// New Export handler for all entries with custom date range
// ==============================
add_action('admin_post_export_forms_all_entries', function() {
    if (isset($_POST['start_date'], $_POST['end_date'])) {
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $form_ids = isset($_POST['selected_forms']) ? array_map('intval', (array)$_POST['selected_forms']) : [10, 9, 3];
        export_forms_by_custom_date_range($start_date, $end_date, $form_ids);
    } else {
        wp_die('Please select a valid date range.');
    }
});

// ==============================
// Export handler for all entries from Jan 1, 2025 to present
// ==============================
add_action('admin_post_export_forms_all_from_2025', function() {
    $start_date = '2025-01-01';
    $end_date = date('Y-m-d');
    $form_ids = isset($_POST['selected_forms']) ? array_map('intval', (array)$_POST['selected_forms']) : [10, 9, 3];
    export_forms_by_custom_date_range($start_date, $end_date, $form_ids);
});

// ==============================
// Prize Drawing Winner Selection Handler
// ==============================
add_action('admin_post_pick_prize_drawing_winners', function() {
    if (isset($_POST['start_date'], $_POST['end_date'])) {
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        pick_prize_drawing_winners($start_date, $end_date);
    } else {
        wp_die('Please select a valid date range.');
    }
});

// ==============================
// Core export
// ==============================
function export_forms_by_date_range($month, $year, $form_ids) {
    global $wpdb;

    // Dates
    $start_date = date('Y-m-d', strtotime("$year-$month-01"));
    $end_date   = date('Y-m-t', strtotime("$year-$month-01"));

    $month_name = date('F', strtotime("$year-$month-01"));
    $date_range = strtolower($month_name) . ' ' . date('j', strtotime($start_date)) . '-' . date('j', strtotime($end_date));

    $combined_data = [];

    foreach ($form_ids as $form_id) {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, form_id, response, source_url, created_at
                 FROM {$wpdb->prefix}fluentform_submissions
                 WHERE form_id = %d
                   AND created_at BETWEEN %s AND %s",
                $form_id, $start_date, $end_date
            ),
            ARRAY_A
        );
        if ($results) {
            $combined_data = array_merge($combined_data, $results);
        }
    }

    if (empty($combined_data)) {
        wp_die('No data available for export within the specified date range.');
    }

    $formatted_data = [];

    foreach ($combined_data as $entry) {
        $response_data = isset($entry['response']) ? json_decode($entry['response'], true) : [];
        if (!is_array($response_data)) {
            $response_data = [];
        }

        // Get form title
        $form_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
                $entry['form_id']
            )
        );

        // Build name safely
        $full_name = '';
        if (isset($response_data['names']['first_name']) || isset($response_data['names']['last_name'])) {
            $first = $response_data['names']['first_name'] ?? '';
            $last  = $response_data['names']['last_name'] ?? '';
            $full_name = trim($first . ' ' . $last);
        }

        $formatted_data[] = [
            'Date Range' => $date_range,
            'Form Name' => $form_name,
            'Email' => $response_data['email'] ?? '',
            'Name' => $full_name,
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
            'Pledge to try by the end of the month' => isset($response_data['checkbox']) ? implode(', ', (array)$response_data['checkbox']) : '',
            'Other Pledge to try' => $response_data['input_text_1'] ?? '',
            'Carpool' => isset($response_data['checkbox_1']) ? implode(', ', (array)$response_data['checkbox_1']) : '',
            'Telework Resources' => isset($response_data['checkbox_7']) ? implode(', ', (array)$response_data['checkbox_7']) : '',
            'Bike' => isset($response_data['checkbox_2']) ? implode(', ', (array)$response_data['checkbox_2']) : '',
            'Transit' => isset($response_data['checkbox_8']) ? implode(', ', (array)$response_data['checkbox_8']) : '',
            'Vanpool' => isset($response_data['checkbox_9']) ? implode(', ', (array)$response_data['checkbox_9']) : '',
            'Guaranteed Ride Home' => isset($response_data['checkbox_10']) ? implode(', ', (array)$response_data['checkbox_10']) : '',
            'I start work at' => $response_data['datetime'] ?? '',
            'I finish work at' => $response_data['datetime_1'] ?? '',
            'Typical way to commute' => $response_data['dropdown'] ?? '',
            'Other way to commute' => $response_data['input_text_1'] ?? '',
            'HM Days do you telework' => $response_data['dropdown_4'] ?? '',
            'HM miles is your one-way commute' => $response_data['numeric_field'] ?? '',
            'HM days a week do you drive alone' => $response_data['dropdown_3'] ?? '',
            'Opt in Form' => (isset($response_data['checkbox_4'][0]) && strtolower($response_data['checkbox_4'][0]) === 'yes') ? 'Yes' : '',
            'Event/Fair Name' => $response_data['input_text_4'] ?? '',
            'Event/Fair Date' => $response_data['datetime_2'] ?? ''
        ];
    }

    $filename = 'forms_export_' . $year . '_' . $month . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, array_keys($formatted_data[0]));
    foreach ($formatted_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// ==============================
// New Core export function for custom date ranges
// ==============================
function export_forms_by_custom_date_range($start_date, $end_date, $form_ids) {
    global $wpdb;

    // Validate dates
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date);

    if (!$start_timestamp || !$end_timestamp) {
        wp_die('Invalid date format provided.');
    }

    // Ensure start date is before end date
    if ($start_timestamp > $end_timestamp) {
        wp_die('Start date must be before end date.');
    }

    // Format dates for display and filename
    $formatted_start_date = date('F j, Y', $start_timestamp);
    $formatted_end_date = date('F j, Y', $end_timestamp);
    $filename_start = date('F_j_Y', $start_timestamp);
    $filename_end = date('F_j_Y', $end_timestamp);

    $combined_data = [];

    foreach ($form_ids as $form_id) {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, form_id, response, source_url, created_at
                 FROM {$wpdb->prefix}fluentform_submissions
                 WHERE form_id = %d
                   AND created_at BETWEEN %s AND %s",
                $form_id, $start_date, $end_date
            ),
            ARRAY_A
        );
        if ($results) {
            $combined_data = array_merge($combined_data, $results);
        }
    }

    if (empty($combined_data)) {
        wp_die('No data available for export within the specified date range.');
    }

    $formatted_data = [];

    foreach ($combined_data as $entry) {
        $response_data = isset($entry['response']) ? json_decode($entry['response'], true) : [];
        if (!is_array($response_data)) {
            $response_data = [];
        }

        // Get form title
        $form_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
                $entry['form_id']
            )
        );

        // Build name safely
        $full_name = '';
        if (isset($response_data['names']['first_name']) || isset($response_data['names']['last_name'])) {
            $first = $response_data['names']['first_name'] ?? '';
            $last  = $response_data['names']['last_name'] ?? '';
            $full_name = trim($first . ' ' . $last);
        }

        $formatted_data[] = [
            'Entry Date' => $entry['created_at'],
            'Form Name' => $form_name,
            'Email' => $response_data['email'] ?? '',
            'Name' => $full_name,
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
            'Pledge to try by the end of the month' => isset($response_data['checkbox']) ? implode(', ', (array)$response_data['checkbox']) : '',
            'Other Pledge to try' => $response_data['input_text_1'] ?? '',
            'Carpool' => isset($response_data['checkbox_1']) ? implode(', ', (array)$response_data['checkbox_1']) : '',
            'Telework Resources' => isset($response_data['checkbox_7']) ? implode(', ', (array)$response_data['checkbox_7']) : '',
            'Bike' => isset($response_data['checkbox_2']) ? implode(', ', (array)$response_data['checkbox_2']) : '',
            'Transit' => isset($response_data['checkbox_8']) ? implode(', ', (array)$response_data['checkbox_8']) : '',
            'Vanpool' => isset($response_data['checkbox_9']) ? implode(', ', (array)$response_data['checkbox_9']) : '',
            'Guaranteed Ride Home' => isset($response_data['checkbox_10']) ? implode(', ', (array)$response_data['checkbox_10']) : '',
            'I start work at' => $response_data['datetime'] ?? '',
            'I finish work at' => $response_data['datetime_1'] ?? '',
            'Typical way to commute' => $response_data['dropdown'] ?? '',
            'Other way to commute' => $response_data['input_text_1'] ?? '',
            'HM Days do you telework' => $response_data['dropdown_4'] ?? '',
            'HM miles is your one-way commute' => $response_data['numeric_field'] ?? '',
            'HM days a week do you drive alone' => $response_data['dropdown_3'] ?? '',
            'Opt in Form' => (isset($response_data['checkbox_4'][0]) && strtolower($response_data['checkbox_4'][0]) === 'yes') ? 'Yes' : '',
            'Event/Fair Name' => $response_data['input_text_4'] ?? '',
            'Event/Fair Date' => $response_data['datetime_2'] ?? ''
        ];
    }

    // Format dates for display and filename
    $formatted_start_date = date('F j, Y', $start_timestamp);
    $formatted_end_date = date('F j, Y', $end_timestamp);
    $filename_start = date('F_j_Y', $start_timestamp);
    $filename_end = date('F_j_Y', $end_timestamp);

    $filename = 'forms_export_' . date('Y-m-d') . '_' . $filename_start . '_to_' . $filename_end . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, array_keys($formatted_data[0]));
    foreach ($formatted_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// ==============================
// Prize Drawing Winner Selection Function
// ==============================
function pick_prize_drawing_winners($start_date, $end_date) {
    global $wpdb;

    // Validate dates
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date);

    if (!$start_timestamp || !$end_timestamp) {
        wp_die('Invalid date format provided.');
    }

    if ($start_timestamp > $end_timestamp) {
        wp_die('Start date must be before end date.');
    }

    // Get Prize Drawings form ID - let's find it dynamically
    $prize_drawings_form_id = null;
    $forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    
    foreach ($forms as $form) {
        if (stripos($form['title'], 'prize') !== false || stripos($form['title'], 'drawing') !== false) {
            $prize_drawings_form_id = $form['id'];
            break;
        }
    }
    
    if (!$prize_drawings_form_id) {
        wp_die('Prize Drawings form not found. Please ensure there is a form with "prize" or "drawing" in the title.');
    }

    // Get all submissions from Prize Drawings form in date range
    $submissions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, form_id, response, created_at
             FROM {$wpdb->prefix}fluentform_submissions
             WHERE form_id = %d
               AND created_at BETWEEN %s AND %s
             ORDER BY created_at ASC",
            $prize_drawings_form_id, $start_date, $end_date
        ),
        ARRAY_A
    );

    if (empty($submissions)) {
        wp_die('No Prize Drawings submissions found in the specified date range.');
    }

    // Process submissions and create weighted entries
    $weighted_entries = [];
    $participants_data = [];

    foreach ($submissions as $submission) {
        $response_data = json_decode($submission['response'], true);
        if (!is_array($response_data)) {
            continue;
        }

        // Extract user information
        $email = $response_data['email'] ?? '';
        $opt_in = false;
        
        // Check for opt-in in various possible field names
        if (isset($response_data['checkbox_4']) && is_array($response_data['checkbox_4'])) {
            $opt_in = in_array('yes', array_map('strtolower', $response_data['checkbox_4']));
        } elseif (isset($response_data['opt_in']) && strtolower($response_data['opt_in']) === 'yes') {
            $opt_in = true;
        } elseif (isset($response_data['newsletter']) && strtolower($response_data['newsletter']) === 'yes') {
            $opt_in = true;
        }

        // Extract additional user information
        $first_name = $response_data['names']['first_name'] ?? '';
        $last_name = $response_data['names']['last_name'] ?? '';
        $full_name = trim($first_name . ' ' . $last_name);
        
        $phone = $response_data['personal_number'] ?? '';
        $address = $response_data['home_address'] ?? '';
        $city = $response_data['home_city'] ?? '';
        $state = $response_data['home_state'] ?? '';
        $zip = $response_data['home_postcode'] ?? '';
        
        // Extract resources/pledges
        $resources = [];
        if (isset($response_data['checkbox']) && is_array($response_data['checkbox'])) {
            $resources[] = 'Pledge: ' . implode(', ', $response_data['checkbox']);
        }
        if (isset($response_data['checkbox_1']) && is_array($response_data['checkbox_1'])) {
            $resources[] = 'Carpool: ' . implode(', ', $response_data['checkbox_1']);
        }
        if (isset($response_data['checkbox_7']) && is_array($response_data['checkbox_7'])) {
            $resources[] = 'Telework: ' . implode(', ', $response_data['checkbox_7']);
        }
        if (isset($response_data['checkbox_2']) && is_array($response_data['checkbox_2'])) {
            $resources[] = 'Bike: ' . implode(', ', $response_data['checkbox_2']);
        }
        if (isset($response_data['checkbox_8']) && is_array($response_data['checkbox_8'])) {
            $resources[] = 'Transit: ' . implode(', ', $response_data['checkbox_8']);
        }
        if (isset($response_data['checkbox_9']) && is_array($response_data['checkbox_9'])) {
            $resources[] = 'Vanpool: ' . implode(', ', $response_data['checkbox_9']);
        }
        if (isset($response_data['checkbox_10']) && is_array($response_data['checkbox_10'])) {
            $resources[] = 'Guaranteed Ride Home: ' . implode(', ', $response_data['checkbox_10']);
        }
        
        $resources_text = implode(' | ', $resources);

        if (empty($email)) {
            continue;
        }

        // Check if user already has entries (persistent opt-in)
        $user_key = strtolower(trim($email));
        
        if (!isset($weighted_entries[$user_key])) {
            $weighted_entries[$user_key] = [
                'email' => $email,
                'name' => $full_name,
                'phone' => $phone,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'zip' => $zip,
                'resources' => $resources_text,
                'entries' => 0,
                'opt_in' => false,
                'submissions' => [],
                'first_submission' => $submission['created_at'],
                'latest_submission' => $submission['created_at'],
                'latest_opt_in' => $opt_in
            ];
        }

        // Update with latest submission data (most recent entry wins)
        $weighted_entries[$user_key]['latest_submission'] = $submission['created_at'];
        $weighted_entries[$user_key]['latest_opt_in'] = $opt_in;
        
        // Update user info with latest submission
        $weighted_entries[$user_key]['name'] = $full_name;
        $weighted_entries[$user_key]['phone'] = $phone;
        $weighted_entries[$user_key]['address'] = $address;
        $weighted_entries[$user_key]['city'] = $city;
        $weighted_entries[$user_key]['state'] = $state;
        $weighted_entries[$user_key]['zip'] = $zip;
        $weighted_entries[$user_key]['resources'] = $resources_text;
        
        // Add entry (but limit to 1 per month)
        $weighted_entries[$user_key]['entries']++;
        $weighted_entries[$user_key]['submissions'][] = $submission['created_at'];
        
        // Update opt-in status (once opted in, stays opted in)
        if ($opt_in) {
            $weighted_entries[$user_key]['opt_in'] = true;
        }

        // Store participant data for export
        $participants_data[] = [
            'Email' => $email,
            'Submission Date' => $submission['created_at'],
            'Opt In' => $opt_in ? 'Yes' : 'No',
            'Entry Number' => $weighted_entries[$user_key]['entries']
        ];
    }

    // Enforce 1 entry per month rule - reset entries to 1 for all users
    foreach ($weighted_entries as $user_key => $user_data) {
        $weighted_entries[$user_key]['entries'] = 1; // Only 1 entry per month regardless of submissions
        $weighted_entries[$user_key]['first_submission'] = $user_data['latest_submission']; // Use latest submission
        $weighted_entries[$user_key]['submissions'] = [$user_data['latest_submission']]; // Only latest submission
    }

    if (empty($weighted_entries)) {
        wp_die('No valid Prize Drawings entries found.');
    }

    // Create weighted pool for random selection
    $weighted_pool = [];
    foreach ($weighted_entries as $user_data) {
        $entries_count = $user_data['entries'];
        $weight = $user_data['opt_in'] ? 2 : 1; // Double weight for opt-in users
        
        // Add entries based on weight
        for ($i = 0; $i < $entries_count; $i++) {
            $weighted_pool[] = [
                'email' => $user_data['email'],
                'name' => $user_data['name'],
                'phone' => $user_data['phone'],
                'address' => $user_data['address'],
                'city' => $user_data['city'],
                'state' => $user_data['state'],
                'zip' => $user_data['zip'],
                'resources' => $user_data['resources'],
                'weight' => $weight,
                'opt_in' => $user_data['opt_in'],
                'total_entries' => $user_data['entries'],
                'first_submission' => $user_data['first_submission'],
                'all_submissions' => implode(', ', $user_data['submissions'])
            ];
        }
    }

    // Randomly select 5 winners
    $winners = [];
    $used_emails = [];
    
    for ($i = 0; $i < 5 && count($winners) < count($weighted_entries); $i++) {
        $random_index = array_rand($weighted_pool);
        $selected_entry = $weighted_pool[$random_index];
        
        // Avoid duplicate winners
        if (!in_array($selected_entry['email'], $used_emails)) {
            $winners[] = [
                'position' => $i + 1,
                'email' => $selected_entry['email'],
                'name' => $selected_entry['name'],
                'phone' => $selected_entry['phone'],
                'address' => $selected_entry['address'],
                'city' => $selected_entry['city'],
                'state' => $selected_entry['state'],
                'zip' => $selected_entry['zip'],
                'resources' => $selected_entry['resources'],
                'weight' => $selected_entry['weight'],
                'opt_in' => $selected_entry['opt_in'],
                'total_entries' => $selected_entry['total_entries'],
                'first_submission' => $selected_entry['first_submission'],
                'all_submissions' => $selected_entry['all_submissions'],
                'winning_chance' => $selected_entry['opt_in'] ? 'Double Entry (Opt-in)' : 'Single Entry'
            ];
            $used_emails[] = $selected_entry['email'];
        }
    }


    // Export winners data
    $filename = 'prize_drawing_winners_' . date('Y-m-d') . '_' . $start_date . '_to_' . $end_date . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    
    // Write winners header
    fputcsv($output, [
        'Position', 
        'Email', 
        'Name', 
        'Winning Chance', 
        'Total Entries', 
        'First Submission', 
        'All Submissions',
        'Phone', 
        'Address', 
        'City', 
        'State', 
        'Zip Code', 
        'Resources/Pledges'
    ]);
    
    foreach ($winners as $winner) {
        fputcsv($output, [
            $winner['position'],
            $winner['email'],
            $winner['name'],
            $winner['winning_chance'],
            $winner['total_entries'],
            $winner['first_submission'],
            $winner['all_submissions'],
            $winner['phone'],
            $winner['address'],
            $winner['city'],
            $winner['state'],
            $winner['zip'],
            $winner['resources']
        ]);
    }
    
    fclose($output);
    exit;
}

// ==============================
// Menu
// ==============================
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

// ==============================
// Admin page + embedded debug console
// ==============================
function export_forms_page() {
    global $wpdb;

    $forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    $default_selected_forms = [10, 9, 3];

    echo '
    <style>
        .wp-core-ui select[multiple] option {
            padding: 10px;
            border: 2px solid lightgray;
            margin: 5px;
            border-radius: 4px;
        }
        .wp-core-ui select[multiple] option:hover { background:#d3d3d369; }
        #available_forms option:checked { background:transparent; }
        .wp-core-ui select[multiple] option:checked,
        #selected_forms option { background:#90ee907d; }
        #export-current-month { margin-right:10px; }

        /* New column styles */
        .export-column h2 {
            color: #23282d;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .export-column h3 {
            margin-bottom: 10px;
            color: #23282d;
        }

        .export-column h4 {
            margin-bottom: 10px;
            color: #666;
            font-size: 14px;
        }

        .date-range-controls {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }

        .quick-date-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .quick-date-buttons .button {
            font-size: 12px;
            padding: 6px 12px;
        }

        .export-all-section {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
        }

        .export-all-section h3 {
            color: #d63638;
            margin-bottom: 8px;
        }

        .export-all-section p {
            margin-bottom: 12px;
            font-size: 13px;
        }

        .prize-drawing-section {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 4px;
            padding: 15px;
            margin-top: 20px;
        }

        .prize-drawing-section h3 {
            color: #2e7d32;
            margin-bottom: 8px;
        }

        .prize-drawing-section p {
            margin-bottom: 12px;
            font-size: 13px;
        }

        /* Form styling improvements */
        #available_forms_right option,
        #selected_forms_right option {
            padding: 8px;
            border: 1px solid #ddd;
            margin: 2px;
            border-radius: 3px;
        }

        #available_forms_right option:hover,
        #selected_forms_right option:hover {
            background: #f0f0f0;
        }

        #available_forms_right option:checked,
        #selected_forms_right option:checked {
            background: #007cba;
            color: white;
        }

        /* Date input styling */
        input[type="date"] {
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 13px;
        }

        input[type="date"]:focus {
            border-color: #007cba;
            box-shadow: 0 0 0 1px #007cba;
            outline: none;
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .wrap > div > div {
                flex-direction: column;
                gap: 20px;
            }
        }
    </style>
    <div class="wrap">
        <h1>Export Forms</h1>
        <p>Select the month, year, and forms to export the combined forms data.</p>
        <div style="display:flex; gap:40px;">
            <!-- Left Column - Existing Functionality -->
            <div style="flex:1;">
                <form method="POST" action="' . esc_url(admin_url('admin-post.php?action=export_forms_custom_month')) . '">
                    <h2>Monthly Export</h2>
                    <div style="display:flex; gap:20px;">
                        <div style="flex:1;">
                            <h3>Available Forms</h3>
                            <select id="available_forms" multiple style="width:100%; height:300px;">';

    foreach ($forms as $form) {
        if (!in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . esc_attr($form['id']) . '">' . esc_html($form['title']) . '</option>';
        }
    }

    echo        '</select>
                        </div>
                        <div style="flex:1;">
                            <h3>Selected Forms</h3>
                            <select name="selected_forms[]" id="selected_forms" multiple required style="width:100%; height:300px;">';

    foreach ($forms as $form) {
        if (in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . esc_attr($form['id']) . '" selected>' . esc_html($form['title']) . '</option>';
        }
    }

    echo        '</select>
                        </div>
                    </div>
                    <br>
                    <label for="selected_month">Month:</label>
                    <select name="selected_month" id="selected_month" required>';
    for ($m=1;$m<=12;$m++) {
        echo '<option value="' . $m . '">' . date('F', strtotime("2000-$m-01")) . '</option>';
    }
    echo    '</select>
                    <label for="selected_year" style="margin-left:10px;">Year:</label>';
    $current_year = date('Y'); $start_year = 2024;
    echo    '<select name="selected_year" id="selected_year" required>';
    for ($y=$start_year;$y<=$current_year;$y++) {
        $sel = $y == $current_year ? 'selected' : '';
        echo "<option value=\"$y\" $sel>$y</option>";
    }
    echo    '</select>
                    <br><br>
                    <button type="submit" id="export-button" class="button button-primary">Export Selected Month</button>
                    <br><br>
                    <h3>Quick Export:</h3>
                    <button type="button" class="button button-secondary" id="export-current-month">Export Current Month</button>
                    <button type="button" class="button button-secondary" id="export-previous-month">Export Previous Month</button>
                </form>
            </div>

            <!-- Right Column - New Functionality -->
            <div style="flex:1;" class="export-column">
                <h2>Custom Date Range Export</h2>
                <p>Select forms and date range to export all entries within that period.</p>

                <form method="POST" action="' . esc_url(admin_url('admin-post.php?action=export_forms_all_entries')) . '">
                    <div style="display:flex; gap:20px; margin-bottom:20px;">
                        <div style="flex:1;">
                            <h3>Available Forms</h3>
                            <select id="available_forms_right" multiple style="width:100%; height:200px;">';

    foreach ($forms as $form) {
        if (!in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . esc_attr($form['id']) . '">' . esc_html($form['title']) . '</option>';
        }
    }

    echo        '</select>
                        </div>
                         <div style="flex:1;">
                             <h3>Selected Forms</h3>
                             <select name="selected_forms[]" id="selected_forms_right" multiple style="width:100%; height:200px;">';

    foreach ($forms as $form) {
        if (in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . esc_attr($form['id']) . '" selected>' . esc_html($form['title']) . '</option>';
        }
    }

    echo        '</select>
                        </div>
                    </div>

                    <!-- Date Range Selection -->
                    <div class="date-range-controls">
                        <h3>Date Range</h3>
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" required style="margin-left:10px; margin-right:20px;">

                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" required style="margin-left:10px;">
                        <br><br>

                        <div class="quick-date-buttons">
                            <button type="button" class="button button-secondary" id="set-range-last-30">Last 30 Days</button>
                            <button type="button" class="button button-secondary" id="set-range-last-90">Last 90 Days</button>
                            <button type="button" class="button button-secondary" id="set-range-this-year">This Year</button>
                            <button type="button" class="button button-secondary" id="set-range-from-2025">From Jan 1, 2025</button>
                        </div>

                        <button type="submit" class="button button-primary">Export Custom Range</button>
                    </div>
                </form>

                <!-- Export All from 2025 Button -->
                <div class="export-all-section">
                    <h3>Quick Export All from 2025</h3>
                    <p>Export all entries from January 1, 2025 to today</p>
                    <form method="POST" action="' . esc_url(admin_url('admin-post.php?action=export_forms_all_from_2025')) . '">
                        <input type="hidden" name="start_date" value="2025-01-01">
                        <input type="hidden" name="end_date" value="' . date('Y-m-d') . '">
                         <select name="selected_forms[]" id="selected_forms_2025_sync" multiple style="display:none;">';

    foreach ($forms as $form) {
        if (in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . esc_attr($form['id']) . '" selected>' . esc_html($form['title']) . '</option>';
        }
    }

    echo        '</select>
                        <button type="submit" class="button button-primary">Export All Entries from 2025</button>
                    </form>
                </div>

                <!-- Prize Drawing Winner Selection -->
                <div class="prize-drawing-section">
                    <h3>Prize Drawing Winner Selection</h3>
                    <p>Select winners for Prize Drawings form with weighted entries (double chance for opt-in users)</p>
                    <form method="POST" action="' . esc_url(admin_url('admin-post.php?action=pick_prize_drawing_winners')) . '">
                        <input type="hidden" name="start_date" id="prize_start_date">
                        <input type="hidden" name="end_date" id="prize_end_date">
                        <button type="submit" class="button button-primary" id="pick-winners-btn">Pick Prize Drawing Winners</button>
                    </form>
                    <p style="font-size: 12px; color: #666; margin-top: 8px;">
                        <strong>Note:</strong> Uses the same date range as selected above. Winners #1-5 will be selected with weighted entries.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        // All comments in English
        function keepAllSelected() {
            const sel = document.getElementById("selected_forms");
            if (!sel) return;
            Array.from(sel.options).forEach(o => o.selected = true);
        }
        function moveOptions(sourceId, targetId) {
            const s = document.getElementById(sourceId);
            const t = document.getElementById(targetId);
            if (!s || !t) return;
            Array.from(s.selectedOptions).forEach(opt => t.appendChild(opt));
        }
        const available = document.getElementById("available_forms");
        const selected = document.getElementById("selected_forms");
        if (available) {
            available.addEventListener("dblclick", ()=> moveOptions("available_forms","selected_forms"));
        }
        if (selected) {
            selected.addEventListener("dblclick", ()=> moveOptions("selected_forms","available_forms"));
            selected.addEventListener("focusout", keepAllSelected);
        }
        document.getElementById("export-button")?.addEventListener("click", ()=> keepAllSelected());

        function quickSubmit(month, year) {
            keepAllSelected();
            const form = document.createElement("form");
            form.method = "POST";
            form.action = "' . esc_js(admin_url('admin-post.php?action=export_forms_custom_month')) . '";
            const mi = document.createElement("input");
            mi.type="hidden"; mi.name="selected_month"; mi.value=month;
            const yi = document.createElement("input");
            yi.type="hidden"; yi.name="selected_year"; yi.value=year;
            form.appendChild(mi); form.appendChild(yi);
            Array.from(document.getElementById("selected_forms").options).forEach(o=>{
                if (o.selected) {
                    const fi = document.createElement("input");
                    fi.type="hidden"; fi.name="selected_forms[]"; fi.value=o.value;
                    form.appendChild(fi);
                }
            });
            document.body.appendChild(form);
            form.submit();
        }
        document.getElementById("export-current-month")?.addEventListener("click", ()=>{
            const d = new Date();
            quickSubmit(d.getMonth()+1, d.getFullYear());
        });
        document.getElementById("export-previous-month")?.addEventListener("click", ()=>{
            const d = new Date();
            const prev = new Date(d.getFullYear(), d.getMonth()-1, 1);
            quickSubmit(prev.getMonth()+1, prev.getFullYear());
        });

        // New functionality for right column
        function moveOptionsRight(sourceId, targetId) {
            const s = document.getElementById(sourceId);
            const t = document.getElementById(targetId);
            if (!s || !t) return;
            Array.from(s.selectedOptions).forEach(opt => t.appendChild(opt));
        }

        // Sync form selections between all columns
        function syncFormSelections() {
            const leftSelected = document.getElementById("selected_forms");
            const rightSelected = document.getElementById("selected_forms_right");
            const right2025Selected = document.getElementById("selected_forms_2025_sync");

            if (!leftSelected || !rightSelected || !right2025Selected) return;

            // Get all values from right column (all options in Selected Forms)
            const rightSelectedValues = Array.from(rightSelected.options).map(opt => opt.value);

            // Sync left column - select all that are in right column
            Array.from(leftSelected.options).forEach(leftOpt => {
                if (rightSelectedValues.includes(leftOpt.value)) {
                    leftOpt.selected = true;
                } else {
                    leftOpt.selected = false;
                }
            });

            // Sync 2025 export form - select all that are in right column
            Array.from(right2025Selected.options).forEach(formOpt => {
                if (rightSelectedValues.includes(formOpt.value)) {
                    formOpt.selected = true;
                } else {
                    formOpt.selected = false;
                }
            });
        }

        // Also sync when right column selection changes
        document.getElementById("selected_forms_right")?.addEventListener("change", syncFormSelections);

         // Ensure all forms in Selected Forms are marked as selected before submitting
         function ensureFormsSelected() {
             const rightSelected = document.getElementById("selected_forms_right");
             const leftSelected = document.getElementById("selected_forms");
             const hiddenSelected = document.getElementById("selected_forms_2025_sync");

             // For right column - all options should be selected since they are in Selected Forms
             if (rightSelected) {
                 Array.from(rightSelected.options).forEach(option => {
                     option.selected = true;
                 });
             }

             // For left column - select all that are in right column
             if (leftSelected) {
                 const rightValues = Array.from(rightSelected.options).map(opt => opt.value);
                 Array.from(leftSelected.options).forEach(option => {
                     option.selected = rightValues.includes(option.value);
                 });
             }

             // For hidden 2025 form - select all that are in right column
             if (hiddenSelected) {
                 const rightValues = Array.from(rightSelected.options).map(opt => opt.value);
                 Array.from(hiddenSelected.options).forEach(option => {
                     option.selected = rightValues.includes(option.value);
                 });
             }
         }

         // Add form preparation to form submissions
         document.querySelector("form[action*=\'export_forms_all_entries\']")?.addEventListener("submit", ensureFormsSelected);
         document.querySelector("form[action*=\'export_forms_all_from_2025\']")?.addEventListener("submit", ensureFormsSelected);

         // Sync date range for prize drawing winner selection
         function syncPrizeDrawingDates() {
             const startDate = document.getElementById("start_date").value;
             const endDate = document.getElementById("end_date").value;
             
             document.getElementById("prize_start_date").value = startDate;
             document.getElementById("prize_end_date").value = endDate;
         }

         // Update prize drawing dates when date range changes
         document.getElementById("start_date")?.addEventListener("change", syncPrizeDrawingDates);
         document.getElementById("end_date")?.addEventListener("change", syncPrizeDrawingDates);

         // Initialize prize drawing dates on page load
         syncPrizeDrawingDates();

        // Quick date range setters for right column
        function setDateRange(startDate, endDate) {
            document.getElementById("start_date").value = startDate;
            document.getElementById("end_date").value = endDate;
        }

        document.getElementById("set-range-last-30")?.addEventListener("click", ()=>{
            const endDate = new Date().toISOString().split("T")[0];
            const startDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split("T")[0];
            setDateRange(startDate, endDate);
            syncPrizeDrawingDates();
        });

        document.getElementById("set-range-last-90")?.addEventListener("click", ()=>{
            const endDate = new Date().toISOString().split("T")[0];
            const startDate = new Date(Date.now() - 90 * 24 * 60 * 60 * 1000).toISOString().split("T")[0];
            setDateRange(startDate, endDate);
            syncPrizeDrawingDates();
        });

        document.getElementById("set-range-this-year")?.addEventListener("click", ()=>{
            const currentYear = new Date().getFullYear();
            const startDate = currentYear + "-01-01";
            const endDate = new Date().toISOString().split("T")[0];
            setDateRange(startDate, endDate);
            syncPrizeDrawingDates();
        });

        document.getElementById("set-range-from-2025")?.addEventListener("click", ()=>{
            setDateRange("2025-01-01", new Date().toISOString().split("T")[0]);
            syncPrizeDrawingDates();
        });

        // Form synchronization events
        document.getElementById("available_forms_right")?.addEventListener("dblclick", ()=>{
            moveOptionsRight("available_forms_right", "selected_forms_right");
            setTimeout(syncFormSelections, 100);
        });

        document.getElementById("selected_forms_right")?.addEventListener("dblclick", ()=>{
            moveOptionsRight("selected_forms_right", "available_forms_right");
            setTimeout(syncFormSelections, 100);
        });

        // Sync selections when left column changes
        document.getElementById("available_forms")?.addEventListener("dblclick", ()=>{
            setTimeout(syncFormSelections, 100);
        });

        document.getElementById("selected_forms")?.addEventListener("dblclick", ()=>{
            setTimeout(syncFormSelections, 100);
        });

        // Also sync when left column selection changes
        document.getElementById("selected_forms")?.addEventListener("change", syncFormSelections);

        // Set default date range to current year when page loads
        const currentYear = new Date().getFullYear();
        setDateRange(currentYear + "-01-01", new Date().toISOString().split("T")[0]);
    });
    </script>
    ';

    // End of function
}
