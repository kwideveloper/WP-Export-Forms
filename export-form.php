<?php
/**
 * Plugin Name: Export Fluent Forms
 * Description: Export combined form data with dynamic date filtering for recent submissions.
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
// Unified Export handler for both monthly and custom date range exports
// ==============================
function handle_export_forms_request() {
    global $wpdb;

    // Check export mode first - prioritize custom date range if both are present
    $is_custom_range = isset($_POST['start_date']) && isset($_POST['end_date']) && 
                      !empty($_POST['start_date']) && !empty($_POST['end_date']);
    $is_monthly = isset($_POST['selected_month']) && isset($_POST['selected_year']) && 
                  !empty($_POST['selected_month']) && !empty($_POST['selected_year']);

    // Check if separate files option is enabled
    $separate_files = isset($_POST['separate_files']) && $_POST['separate_files'] == '1';
    $form_ids = isset($_POST['selected_forms']) ? array_map('intval', (array)$_POST['selected_forms']) : [10, 9, 3];

    // Prioritize custom date range if both are available
    if ($is_custom_range) {
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);

        // Call the appropriate export function based on separate files option
        if ($separate_files && count($form_ids) > 1) {
            export_forms_by_custom_date_range_separate($start_date, $end_date, $form_ids);
        } else {
            export_forms_by_custom_date_range($start_date, $end_date, $form_ids);
        }
    }
    // Fallback to monthly export
    elseif ($is_monthly) {
        $month = intval($_POST['selected_month']);
        $year = intval($_POST['selected_year']);

        // Call the appropriate export function based on separate files option
        if ($separate_files && count($form_ids) > 1) {
            export_forms_by_date_range_separate($month, $year, $form_ids);
        } else {
            export_forms_by_date_range($month, $year, $form_ids);
        }
    }
    else {
        wp_die('Please select a valid export option and ensure date fields are properly filled.');
    }
}

add_action('admin_post_export_forms_all_entries', 'handle_export_forms_request');

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
    // Check if it's monthly export mode
    if (isset($_POST['selected_month'], $_POST['selected_year']) && 
        !empty($_POST['selected_month']) && !empty($_POST['selected_year'])) {
        $month = intval($_POST['selected_month']);
        $year = intval($_POST['selected_year']);
        
        // Calculate date range for the month
        $start_date = date('Y-m-d', strtotime("$year-$month-01"));
        $end_date = date('Y-m-t', strtotime("$year-$month-01"));
        
        pick_prize_drawing_winners($start_date, $end_date);
    }
    // Check if it's custom date range mode
    elseif (isset($_POST['start_date'], $_POST['end_date']) && 
            !empty($_POST['start_date']) && !empty($_POST['end_date'])) {
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);

        // Try to convert date formats if necessary
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $start_date)) {
            // Convert from MM/DD/YYYY to YYYY-MM-DD
            $date_parts = explode('/', $start_date);
            $start_date = $date_parts[2] . '-' . $date_parts[0] . '-' . $date_parts[1];
        }
        
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $end_date)) {
            // Convert from MM/DD/YYYY to YYYY-MM-DD
            $date_parts = explode('/', $end_date);
            $end_date = $date_parts[2] . '-' . $date_parts[0] . '-' . $date_parts[1];
        }
        
        pick_prize_drawing_winners($start_date, $end_date);
    } else {
        wp_die('Please select a valid date range. No date parameters were found in the request.');
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
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date . ' 23:59:59');

    $month_name = date('F', strtotime("$year-$month-01"));
    $date_range = strtolower($month_name) . ' ' . date('j', strtotime($start_date)) . '-' . date('j', strtotime($end_date));

    // Get all forms to identify Manual Entry forms
    $all_forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    $manual_entry_form_ids = [];
    
    foreach ($all_forms as $form) {
        // Identify Manual Entry forms
        if (stripos($form['title'], 'manual') !== false || 
            stripos($form['title'], 'entry') !== false ||
            stripos($form['title'], 'fair') !== false ||
            stripos($form['title'], 'event') !== false) {
            $manual_entry_form_ids[] = $form['id'];
        }
    }

    $combined_data = [];

    foreach ($form_ids as $form_id) {
        // Check if this is a Manual Entry form
        $is_manual_entry = in_array($form_id, $manual_entry_form_ids);
        
        if ($is_manual_entry) {
            // For Manual Entry: Get ALL entries and filter by Event/Fair Date in PHP
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, form_id, response, source_url, created_at
                     FROM {$wpdb->prefix}fluentform_submissions
                     WHERE form_id = %d",
                    $form_id
                ),
                ARRAY_A
            );
            
            // Filter by Event/Fair Date
            foreach ($results as $entry) {
                $response_data = json_decode($entry['response'], true);
                if (!is_array($response_data)) {
                    continue;
                }
                
                // Try to find Event/Fair Date
                $event_date_field = null;
                if (isset($response_data['datetime_2']) && !empty($response_data['datetime_2'])) {
                    $event_date_field = $response_data['datetime_2'];
                } elseif (isset($response_data['datetime_1']) && !empty($response_data['datetime_1'])) {
                    $event_date_field = $response_data['datetime_1'];
                } elseif (isset($response_data['datetime']) && !empty($response_data['datetime'])) {
                    $event_date_field = $response_data['datetime'];
                } elseif (isset($response_data['event_date']) && !empty($response_data['event_date'])) {
                    $event_date_field = $response_data['event_date'];
                } elseif (isset($response_data['fair_date']) && !empty($response_data['fair_date'])) {
                    $event_date_field = $response_data['fair_date'];
                }
                
                // Only include if Event/Fair Date is within range
                if ($event_date_field) {
                    $event_timestamp = strtotime($event_date_field);
                    if ($event_timestamp && $event_timestamp >= $start_timestamp && $event_timestamp <= $end_timestamp) {
                        $combined_data[] = $entry;
                    }
                }
            }
        } else {
            // For other forms: Use created_at as before
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

        // Get Event/Fair Date from response or use end_date as fallback
        $event_fair_date = $response_data['datetime_2'] ?? date('Y-m-d', strtotime($end_date));

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
            'Event/Fair Date' => $event_fair_date
        ];
    }

    // Generate filename with form names
    $form_names = [];
    foreach ($form_ids as $form_id) {
        $form_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
                $form_id
            )
        );
        if ($form_name) {
            // Clean form name for filename (remove special characters, spaces)
            $clean_name = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $form_name));
            $clean_name = str_replace(' ', '_', trim($clean_name));
            $form_names[] = $clean_name;
        }
    }
    
    $form_names_str = !empty($form_names) ? implode('_', $form_names) . '_' : '';
    $month_name = strtolower(date('F', strtotime("$year-$month-01")));
    $filename = $form_names_str . $month_name . '_' . $year . '.csv';
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
    $end_timestamp = strtotime($end_date . ' 23:59:59');

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

    // Get all forms to identify Manual Entry forms
    $all_forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    $manual_entry_form_ids = [];
    
    foreach ($all_forms as $form) {
        // Identify Manual Entry forms
        if (stripos($form['title'], 'manual') !== false || 
            stripos($form['title'], 'entry') !== false ||
            stripos($form['title'], 'fair') !== false ||
            stripos($form['title'], 'event') !== false) {
            $manual_entry_form_ids[] = $form['id'];
        }
    }

    $combined_data = [];

    foreach ($form_ids as $form_id) {
        // Check if this is a Manual Entry form
        $is_manual_entry = in_array($form_id, $manual_entry_form_ids);
        
        if ($is_manual_entry) {
            // For Manual Entry: Get ALL entries and filter by Event/Fair Date in PHP
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, form_id, response, source_url, created_at
                     FROM {$wpdb->prefix}fluentform_submissions
                     WHERE form_id = %d",
                    $form_id
                ),
                ARRAY_A
            );
            
            // Filter by Event/Fair Date
            foreach ($results as $entry) {
                $response_data = json_decode($entry['response'], true);
                if (!is_array($response_data)) {
                    continue;
                }
                
                // Try to find Event/Fair Date
                $event_date_field = null;
                if (isset($response_data['datetime_2']) && !empty($response_data['datetime_2'])) {
                    $event_date_field = $response_data['datetime_2'];
                } elseif (isset($response_data['datetime_1']) && !empty($response_data['datetime_1'])) {
                    $event_date_field = $response_data['datetime_1'];
                } elseif (isset($response_data['datetime']) && !empty($response_data['datetime'])) {
                    $event_date_field = $response_data['datetime'];
                } elseif (isset($response_data['event_date']) && !empty($response_data['event_date'])) {
                    $event_date_field = $response_data['event_date'];
                } elseif (isset($response_data['fair_date']) && !empty($response_data['fair_date'])) {
                    $event_date_field = $response_data['fair_date'];
                }
                
                // Only include if Event/Fair Date is within range
                if ($event_date_field) {
                    $event_timestamp = strtotime($event_date_field);
                    if ($event_timestamp && $event_timestamp >= $start_timestamp && $event_timestamp <= $end_timestamp) {
                        $combined_data[] = $entry;
                    }
                }
            }
        } else {
            // For other forms: Use created_at as before
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

        // Get Event/Fair Date from response or use end_date as fallback
        $event_fair_date = $response_data['datetime_2'] ?? date('Y-m-d', $end_timestamp);

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
            'Event/Fair Date' => $event_fair_date
        ];
    }

    // Generate filename with form names
    $form_names = [];
    foreach ($form_ids as $form_id) {
        $form_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
                $form_id
            )
        );
        if ($form_name) {
            // Clean form name for filename (remove special characters, spaces)
            $clean_name = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $form_name));
            $clean_name = str_replace(' ', '_', trim($clean_name));
            $form_names[] = $clean_name;
        }
    }
    
    $form_names_str = !empty($form_names) ? implode('_', $form_names) . '_' : '';
    $start_month = strtolower(date('F', $start_timestamp));
    $end_month = strtolower(date('F', $end_timestamp));
    $start_year = date('Y', $start_timestamp);
    $end_year = date('Y', $end_timestamp);
    
    // Format date range for filename
    if ($start_year == $end_year) {
        $date_range = $start_month . '_to_' . $end_month . '_' . $start_year;
    } else {
        $date_range = $start_month . '_' . $start_year . '_to_' . $end_month . '_' . $end_year;
    }
    
    $filename = $form_names_str . $date_range . '.csv';
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
// Export functions for separate files (one per form)
// ==============================
function export_forms_by_date_range_separate($month, $year, $form_ids) {
    global $wpdb;

    // Dates
    $start_date = date('Y-m-d', strtotime("$year-$month-01"));
    $end_date   = date('Y-m-t', strtotime("$year-$month-01"));
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date . ' 23:59:59');

    $month_name = date('F', strtotime("$year-$month-01"));
    $date_range = strtolower($month_name) . ' ' . date('j', strtotime($start_date)) . '-' . date('j', strtotime($end_date));

    // Get all forms to identify Manual Entry forms
    $all_forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    $manual_entry_form_ids = [];
    
    foreach ($all_forms as $form) {
        // Identify Manual Entry forms
        if (stripos($form['title'], 'manual') !== false || 
            stripos($form['title'], 'entry') !== false ||
            stripos($form['title'], 'fair') !== false ||
            stripos($form['title'], 'event') !== false) {
            $manual_entry_form_ids[] = $form['id'];
        }
    }

    // Create ZIP file for multiple forms
    $zip_filename = 'forms_export_' . strtolower($month_name) . '_' . $year . '.zip';
    
    // Create temporary directory for files
    $temp_dir = sys_get_temp_dir() . '/export_' . uniqid();
    if (!mkdir($temp_dir, 0755, true)) {
        wp_die('Could not create temporary directory for export.');
    }

    $zip = new ZipArchive();
    $zip_path = $temp_dir . '/' . $zip_filename;
    
    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        wp_die('Could not create ZIP file for export.');
    }

    foreach ($form_ids as $form_id) {
        // Check if this is a Manual Entry form
        $is_manual_entry = in_array($form_id, $manual_entry_form_ids);
        
        if ($is_manual_entry) {
            // For Manual Entry: Get ALL entries and filter by Event/Fair Date in PHP
            $all_results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, form_id, response, source_url, created_at
                     FROM {$wpdb->prefix}fluentform_submissions
                     WHERE form_id = %d",
                    $form_id
                ),
                ARRAY_A
            );
            
            // Filter by Event/Fair Date
            $results = [];
            foreach ($all_results as $entry) {
                $response_data = json_decode($entry['response'], true);
                if (!is_array($response_data)) {
                    continue;
                }
                
                // Try to find Event/Fair Date
                $event_date_field = null;
                if (isset($response_data['datetime_2']) && !empty($response_data['datetime_2'])) {
                    $event_date_field = $response_data['datetime_2'];
                } elseif (isset($response_data['datetime_1']) && !empty($response_data['datetime_1'])) {
                    $event_date_field = $response_data['datetime_1'];
                } elseif (isset($response_data['datetime']) && !empty($response_data['datetime'])) {
                    $event_date_field = $response_data['datetime'];
                } elseif (isset($response_data['event_date']) && !empty($response_data['event_date'])) {
                    $event_date_field = $response_data['event_date'];
                } elseif (isset($response_data['fair_date']) && !empty($response_data['fair_date'])) {
                    $event_date_field = $response_data['fair_date'];
                }
                
                // Only include if Event/Fair Date is within range
                if ($event_date_field) {
                    $event_timestamp = strtotime($event_date_field);
                    if ($event_timestamp && $event_timestamp >= $start_timestamp && $event_timestamp <= $end_timestamp) {
                        $results[] = $entry;
                    }
                }
            }
        } else {
            // For other forms: Use created_at as before
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
        }

        if (empty($results)) {
            continue; // Skip forms with no data
        }

        // Get form title
        $form_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
                $form_id
            )
        );

        $formatted_data = [];
        foreach ($results as $entry) {
            $response_data = isset($entry['response']) ? json_decode($entry['response'], true) : [];
            if (!is_array($response_data)) {
                $response_data = [];
            }

            // Build name safely
            $full_name = '';
            if (isset($response_data['names']['first_name']) || isset($response_data['names']['last_name'])) {
                $first = $response_data['names']['first_name'] ?? '';
                $last  = $response_data['names']['last_name'] ?? '';
                $full_name = trim($first . ' ' . $last);
            }

            // Get Event/Fair Date from response or use end_date as fallback
            $event_fair_date = $response_data['datetime_2'] ?? date('Y-m-d', strtotime($end_date));

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
                'Event/Fair Date' => $event_fair_date
            ];
        }

        // Create CSV file for this form
        $clean_form_name = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $form_name));
        $clean_form_name = str_replace(' ', '_', trim($clean_form_name));
        $csv_filename = $clean_form_name . '_' . strtolower($month_name) . '_' . $year . '.csv';
        
        $csv_content = '';
        $csv_content .= implode(',', array_map(function($field) {
            return '"' . str_replace('"', '""', $field) . '"';
        }, array_keys($formatted_data[0]))) . "\n";
        
        foreach ($formatted_data as $row) {
            $csv_content .= implode(',', array_map(function($field) {
                return '"' . str_replace('"', '""', $field) . '"';
            }, $row)) . "\n";
        }
        
        $zip->addFromString($csv_filename, $csv_content);
    }

    $zip->close();

    // Send ZIP file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    
    readfile($zip_path);
    
    // Clean up
    unlink($zip_path);
    rmdir($temp_dir);
    
    exit;
}

function export_forms_by_custom_date_range_separate($start_date, $end_date, $form_ids) {
    global $wpdb;

    // Validate dates
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date . ' 23:59:59');

    if (!$start_timestamp || !$end_timestamp) {
        wp_die('Invalid date format provided.');
    }

    // Ensure start date is before end date
    if ($start_timestamp > $end_timestamp) {
        wp_die('Start date must be before end date.');
    }

    // Format dates for filename
    $filename_start = date('F_j_Y', $start_timestamp);
    $filename_end = date('F_j_Y', $end_timestamp);
    
    // Get all forms to identify Manual Entry forms
    $all_forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    $manual_entry_form_ids = [];
    
    foreach ($all_forms as $form) {
        // Identify Manual Entry forms
        if (stripos($form['title'], 'manual') !== false || 
            stripos($form['title'], 'entry') !== false ||
            stripos($form['title'], 'fair') !== false ||
            stripos($form['title'], 'event') !== false) {
            $manual_entry_form_ids[] = $form['id'];
        }
    }
    
    // Create ZIP file for multiple forms
    $zip_filename = 'forms_export_' . $filename_start . '_to_' . $filename_end . '.zip';
    
    // Create temporary directory for files
    $temp_dir = sys_get_temp_dir() . '/export_' . uniqid();
    if (!mkdir($temp_dir, 0755, true)) {
        wp_die('Could not create temporary directory for export.');
    }

    $zip = new ZipArchive();
    $zip_path = $temp_dir . '/' . $zip_filename;
    
    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        wp_die('Could not create ZIP file for export.');
    }

    foreach ($form_ids as $form_id) {
        // Check if this is a Manual Entry form
        $is_manual_entry = in_array($form_id, $manual_entry_form_ids);
        
        if ($is_manual_entry) {
            // For Manual Entry: Get ALL entries and filter by Event/Fair Date in PHP
            $all_results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, form_id, response, source_url, created_at
                     FROM {$wpdb->prefix}fluentform_submissions
                     WHERE form_id = %d",
                    $form_id
                ),
                ARRAY_A
            );
            
            // Filter by Event/Fair Date
            $results = [];
            foreach ($all_results as $entry) {
                $response_data = json_decode($entry['response'], true);
                if (!is_array($response_data)) {
                    continue;
                }
                
                // Try to find Event/Fair Date
                $event_date_field = null;
                if (isset($response_data['datetime_2']) && !empty($response_data['datetime_2'])) {
                    $event_date_field = $response_data['datetime_2'];
                } elseif (isset($response_data['datetime_1']) && !empty($response_data['datetime_1'])) {
                    $event_date_field = $response_data['datetime_1'];
                } elseif (isset($response_data['datetime']) && !empty($response_data['datetime'])) {
                    $event_date_field = $response_data['datetime'];
                } elseif (isset($response_data['event_date']) && !empty($response_data['event_date'])) {
                    $event_date_field = $response_data['event_date'];
                } elseif (isset($response_data['fair_date']) && !empty($response_data['fair_date'])) {
                    $event_date_field = $response_data['fair_date'];
                }
                
                // Only include if Event/Fair Date is within range
                if ($event_date_field) {
                    $event_timestamp = strtotime($event_date_field);
                    if ($event_timestamp && $event_timestamp >= $start_timestamp && $event_timestamp <= $end_timestamp) {
                        $results[] = $entry;
                    }
                }
            }
        } else {
            // For other forms: Use created_at as before
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
        }

        if (empty($results)) {
            continue; // Skip forms with no data
        }

        // Get form title
        $form_name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}fluentform_forms WHERE id = %d",
                $form_id
            )
        );

        $formatted_data = [];
        foreach ($results as $entry) {
            $response_data = isset($entry['response']) ? json_decode($entry['response'], true) : [];
            if (!is_array($response_data)) {
                $response_data = [];
            }

            // Build name safely
            $full_name = '';
            if (isset($response_data['names']['first_name']) || isset($response_data['names']['last_name'])) {
                $first = $response_data['names']['first_name'] ?? '';
                $last  = $response_data['names']['last_name'] ?? '';
                $full_name = trim($first . ' ' . $last);
            }

            // Get Event/Fair Date from response or use end_date as fallback
            $event_fair_date = $response_data['datetime_2'] ?? date('Y-m-d', $end_timestamp);

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
                'Event/Fair Date' => $event_fair_date
            ];
        }

        // Create CSV file for this form
        $clean_form_name = strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $form_name));
        $clean_form_name = str_replace(' ', '_', trim($clean_form_name));
        $csv_filename = $clean_form_name . '_' . $filename_start . '_to_' . $filename_end . '.csv';
        
        $csv_content = '';
        $csv_content .= implode(',', array_map(function($field) {
            return '"' . str_replace('"', '""', $field) . '"';
        }, array_keys($formatted_data[0]))) . "\n";
        
        foreach ($formatted_data as $row) {
            $csv_content .= implode(',', array_map(function($field) {
                return '"' . str_replace('"', '""', $field) . '"';
            }, $row)) . "\n";
        }
        
        $zip->addFromString($csv_filename, $csv_content);
    }

    $zip->close();

    // Send ZIP file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    
    readfile($zip_path);
    
    // Clean up
    unlink($zip_path);
    rmdir($temp_dir);
    
    exit;
}

// ==============================
// Prize Drawing Winner Selection Function
// ==============================
function pick_prize_drawing_winners($start_date, $end_date) {
    global $wpdb;

    // Validate and correct dates
    $start_timestamp = strtotime($start_date);
    $end_timestamp = strtotime($end_date);

    // If dates are not valid, use recent default dates
    if (!$start_timestamp || !$end_timestamp) {
        // Use current month as default
        $current_year = date('Y');
        $current_month = date('n');
        $start_date = date('Y-m-d', strtotime("$current_year-$current_month-01"));
        $end_date = date('Y-m-t', strtotime($start_date));
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
    }

    // Ensure that start_date is before end_date
    if ($start_timestamp > $end_timestamp) {
        $temp = $start_date;
        $start_date = $end_date;
        $end_date = $temp;
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
    }

    // Get Prize Drawings and Manual Entry form IDs
    $prize_drawings_form_id = null;
    $manual_entry_form_id = null;
    $forms = $wpdb->get_results("SELECT id, title FROM {$wpdb->prefix}fluentform_forms", ARRAY_A);
    
    foreach ($forms as $form) {
        // More specific detection for Prize Drawings (exact match for "Prize Drawings")
        if (stripos($form['title'], 'prize') !== false && stripos($form['title'], 'drawing') !== false) {
            $prize_drawings_form_id = $form['id'];
        }
        // More flexible detection for Manual Entry
        if (stripos($form['title'], 'manual') !== false || 
            stripos($form['title'], 'entry') !== false ||
            stripos($form['title'], 'fair') !== false ||
            stripos($form['title'], 'event') !== false) {
            $manual_entry_form_id = $form['id'];
        }
    }
    
    if (!$prize_drawings_form_id && !$manual_entry_form_id) {
        wp_die('Prize Drawings or Manual Entry form not found.');
    }

    $all_submissions = [];

    // Get submissions from Prize Drawings form (using created_at date - entry date)
    if ($prize_drawings_form_id) {
        // Filter by created_at date range for Prize Drawings
        $formatted_start_date = date('Y-m-d H:i:s', $start_timestamp);
        $formatted_end_date = date('Y-m-d 23:59:59', $end_timestamp);
        
        $prize_submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, form_id, response, created_at
                 FROM {$wpdb->prefix}fluentform_submissions
                 WHERE form_id = %d
                   AND created_at BETWEEN %s AND %s
                 ORDER BY created_at ASC",
                $prize_drawings_form_id, $formatted_start_date, $formatted_end_date
            ),
            ARRAY_A
        );
        
        foreach ($prize_submissions as $submission) {
            $submission['form_type'] = 'Prize Drawings';
            $submission['date_used'] = 'Entry Date (created_at)';
            $all_submissions[] = $submission;
        }
    }

    // Get submissions from Manual Entry form (using Event/Fair Date only)
    if ($manual_entry_form_id) {
        // Get ALL Manual Entry submissions first (we need to check Event/Fair Date, not created_at)
        $all_manual_submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, form_id, response, created_at
                 FROM {$wpdb->prefix}fluentform_submissions
                 WHERE form_id = %d",
                $manual_entry_form_id
            ),
            ARRAY_A
        );
        
        foreach ($all_manual_submissions as $submission) {
            $response_data = json_decode($submission['response'], true);
            
            // Try different possible field names for Event/Fair Date
            $event_date_field = null;
            if (isset($response_data['datetime_2']) && !empty($response_data['datetime_2'])) {
                $event_date_field = $response_data['datetime_2'];
            } elseif (isset($response_data['datetime_1']) && !empty($response_data['datetime_1'])) {
                $event_date_field = $response_data['datetime_1'];
            } elseif (isset($response_data['datetime']) && !empty($response_data['datetime'])) {
                $event_date_field = $response_data['datetime'];
            } elseif (isset($response_data['event_date']) && !empty($response_data['event_date'])) {
                $event_date_field = $response_data['event_date'];
            } elseif (isset($response_data['fair_date']) && !empty($response_data['fair_date'])) {
                $event_date_field = $response_data['fair_date'];
            } elseif (isset($response_data['date']) && !empty($response_data['date'])) {
                $event_date_field = $response_data['date'];
            } elseif (isset($response_data['event_fair_date']) && !empty($response_data['event_fair_date'])) {
                $event_date_field = $response_data['event_fair_date'];
            } elseif (isset($response_data['event_fair']) && !empty($response_data['event_fair'])) {
                $event_date_field = $response_data['event_fair'];
            } elseif (isset($response_data['fair']) && !empty($response_data['fair'])) {
                $event_date_field = $response_data['fair'];
            } elseif (isset($response_data['event']) && !empty($response_data['event'])) {
                $event_date_field = $response_data['event'];
            }
            
            // For Manual Entry, ONLY use Event/Fair Date - if no Event/Fair Date found, skip this submission
            if ($event_date_field) {
                // Parse Event/Fair Date
                $event_date = strtotime($event_date_field);
                
                if ($event_date && $event_date >= $start_timestamp && $event_date <= $end_timestamp) {
                    $submission['form_type'] = 'Manual Entry';
                    $submission['date_used'] = 'Event/Fair Date: ' . $event_date_field;
                    $all_submissions[] = $submission;
                }
            }
            // Skip submissions without Event/Fair Date - we don't use created_at for Manual Entry
        }
    }

    $submissions = $all_submissions;

    if (empty($submissions)) {
        wp_die('No Prize Drawings submissions found in the specified date range (' . $start_date . ' to ' . $end_date . ').');
    }

    // The rest of the function remains the same...
    // Process submissions and create weighted entries
    $weighted_entries = [];
    $participants_data = [];

    foreach ($submissions as $submission) {
        $response_data = json_decode($submission['response'], true);
        if (!is_array($response_data)) {
            continue;
        }

        // Determine the correct date to use based on form type
        $submission_date = $submission['created_at']; // Default to created_at
        
        if ($submission['form_type'] === 'Manual Entry') {
            // For Manual Entry, find and use the Event/Fair Date
            $event_date_field = null;
            if (isset($response_data['datetime_2']) && !empty($response_data['datetime_2'])) {
                $event_date_field = $response_data['datetime_2'];
            } elseif (isset($response_data['datetime_1']) && !empty($response_data['datetime_1'])) {
                $event_date_field = $response_data['datetime_1'];
            } elseif (isset($response_data['datetime']) && !empty($response_data['datetime'])) {
                $event_date_field = $response_data['datetime'];
            } elseif (isset($response_data['event_date']) && !empty($response_data['event_date'])) {
                $event_date_field = $response_data['event_date'];
            } elseif (isset($response_data['fair_date']) && !empty($response_data['fair_date'])) {
                $event_date_field = $response_data['fair_date'];
            } elseif (isset($response_data['date']) && !empty($response_data['date'])) {
                $event_date_field = $response_data['date'];
            } elseif (isset($response_data['event_fair_date']) && !empty($response_data['event_fair_date'])) {
                $event_date_field = $response_data['event_fair_date'];
            } elseif (isset($response_data['event_fair']) && !empty($response_data['event_fair'])) {
                $event_date_field = $response_data['event_fair'];
            } elseif (isset($response_data['fair']) && !empty($response_data['fair'])) {
                $event_date_field = $response_data['fair'];
            } elseif (isset($response_data['event']) && !empty($response_data['event'])) {
                $event_date_field = $response_data['event'];
            }
            
            if ($event_date_field) {
                $event_timestamp = strtotime($event_date_field);
                if ($event_timestamp) {
                    $submission_date = date('Y-m-d H:i:s', $event_timestamp);
                }
            }
        }
        // For Prize Drawings, use created_at (already set as default)

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
                'first_submission' => $submission_date,
                'latest_submission' => $submission_date,
                'latest_opt_in' => $opt_in
            ];
        }

        // Update with latest submission data (most recent entry wins)
        $weighted_entries[$user_key]['latest_submission'] = $submission_date;
        $weighted_entries[$user_key]['latest_opt_in'] = $opt_in;
        
        // Update user info with latest submission
        $weighted_entries[$user_key]['name'] = $full_name;
        $weighted_entries[$user_key]['phone'] = $phone;
        $weighted_entries[$user_key]['address'] = $address;
        $weighted_entries[$user_key]['city'] = $city;
        $weighted_entries[$user_key]['state'] = $state;
        $weighted_entries[$user_key]['zip'] = $zip;
        $weighted_entries[$user_key]['resources'] = $resources_text;
        $weighted_entries[$user_key]['form_type'] = $submission['form_type'];
        
        // Add entry (but limit to 1 per month)
        $weighted_entries[$user_key]['entries']++;
        $weighted_entries[$user_key]['submissions'][] = $submission_date;
        
        // Update opt-in status (once opted in, stays opted in)
        if ($opt_in) {
            $weighted_entries[$user_key]['opt_in'] = true;
        }

        // Store participant data for export
        $participants_data[] = [
            'Email' => $email,
            'Submission Date' => $submission_date,
            'Form Name' => $submission['form_type'],
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
        for ($i = 0; $i < $entries_count * $weight; $i++) {
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
                'all_submissions' => implode(', ', $user_data['submissions']),
                'form_type' => $user_data['form_type'] ?? 'Unknown'
            ];
        }
    }

    // Randomly select 5 winners
    $winners = [];
    $used_emails = [];
    
    for ($i = 0; $i < 5 && count($winners) < count($weighted_entries); $i++) {
        if (empty($weighted_pool)) {
            break; // No more entries to select
        }
        
        $random_index = array_rand($weighted_pool);
        $selected_entry = $weighted_pool[$random_index];
        
        // Avoid duplicate winners
        if (!in_array($selected_entry['email'], $used_emails)) {
            $winners[] = [
                'position' => $i + 1,
                'email' => $selected_entry['email'],
                'name' => $selected_entry['name'],
                'form_name' => $selected_entry['form_type'],
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
            
            // Remove all entries with this email
            $weighted_pool = array_filter($weighted_pool, function($entry) use ($selected_entry) {
                return $entry['email'] !== $selected_entry['email'];
            });
        }
    }

    // Export winners data
    $formatted_start_date = date('Y-m-d', $start_timestamp);
    $formatted_end_date = date('Y-m-d', $end_timestamp);
    $filename = 'prize_drawing_winners_' . date('Y-m-d') . '_' . $formatted_start_date . '_to_' . $formatted_end_date . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    
    // Write winners header
    fputcsv($output, [
        'Position', 
        'Email', 
        'Name', 
        'Form Name',
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
            $winner['form_name'],
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
// Admin page
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

        /* Minimalist column styles */
        .export-column h2 {
            color: #1f2937;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 12px;
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 500;
        }

        .export-column h3 {
            margin-bottom: 12px;
            color: #374151;
            font-size: 16px;
            font-weight: 500;
        }

        .export-column h4 {
            margin-bottom: 8px;
            color: #6b7280;
            font-size: 13px;
            font-weight: 500;
        }

        .date-range-controls {
            background: #f9fafb;
            padding: 16px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }

        .quick-date-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .quick-date-buttons .button {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .export-all-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 16px;
        }

        .export-all-section h3 {
            color: #475569;
            margin-bottom: 8px;
            font-size: 16px;
            font-weight: 500;
        }

        .export-all-section p {
            margin-bottom: 12px;
            font-size: 13px;
            color: #6b7280;
        }

        .prize-drawing-section {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 6px;
            padding: 16px;
            margin-top: 20px;
        }

        .prize-drawing-section h3 {
            color: #16a34a;
            margin-bottom: 8px;
            font-size: 16px;
            font-weight: 500;
        }

        .prize-drawing-section p {
            margin-bottom: 12px;
            font-size: 13px;
            color: #6b7280;
        }

        /* Minimalist form styling */
        #available_forms_right option,
        #selected_forms_right option {
            padding: 6px;
            border: 1px solid #e5e7eb;
            margin: 2px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        #available_forms_right option:hover,
        #selected_forms_right option:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        #available_forms_right option:checked,
        #selected_forms_right option:checked {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        /* Minimalist input styling */
        input[type="date"] {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.2s ease;
            background: white;
        }

        input[type="date"]:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
            outline: none;
        }

        /* Minimalist select styling */
        select {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.2s ease;
            background: white;
        }

        select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
            outline: none;
        }

        /* Export mode selection styling */
        input[type="radio"] {
            margin-right: 6px;
        }

        /* Monthly controls styling */
        #monthly_controls select {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
            transition: all 0.2s ease;
        }

        /* Minimalist button styling */
        .button {
            border-radius: 4px;
            transition: all 0.2s ease;
            font-size: 13px;
            padding: 6px 12px;
            border: 1px solid #d1d5db;
            background: white;
        }

        .button:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }

        .button-primary {
            background: #3b82f6;
            border-color: #3b82f6;
            color: white;
        }

        .button-primary:hover {
            background: #2563eb;
            border-color: #2563eb;
        }

        .button-secondary {
            background: white;
            border: 1px solid #d1d5db;
            color: #374151;
        }

        .button-secondary:hover {
            background: #f9fafb;
            border-color: #9ca3af;
        }

        /* Quick export buttons styling */
        .quick-export-buttons .button {
            margin-right: 12px;
        }

        /* Minimalist form containers */
        .form-container {
            background: white;
            border-radius: 6px;
            padding: 20px;
            border: 1px solid #e5e7eb;
        }

        /* Minimalist labels */
        label {
            font-weight: 500;
            color: #374151;
            font-size: 13px;
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .wrap > div > div {
                flex-direction: column;
                gap: 20px;
            }
        }

        /* Minimalist wrap styling */
        .wrap {
            background: #f9fafb;
            padding: 16px;
            border-radius: 6px;
        }

        .wrap h1 {
            color: #1f2937;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .wrap > p {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 24px;
        }
    </style>
    <div class="wrap">
        <h1>Export Forms</h1>
        <p>Select forms and date range to export combined forms data with flexible options.</p>
        <div style="max-width: 1200px;">
            <!-- Unified Export Section -->
            <div style="flex:1;" class="export-column form-container">
                <h2>Forms Export</h2>
                <p>Export form data using monthly selection or custom date ranges.</p>

                <!-- Export Mode Selection -->
                <div style="margin-bottom: 20px; padding: 16px; background: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb;">
                    <h3 style="margin-top: 0; color: #374151;">Export Mode</h3>
                    <label style="margin-right: 20px;">
                        <input type="radio" name="export_mode" value="month" id="export_mode_month" checked>
                        Monthly Export
                    </label>
                    <label>
                        <input type="radio" name="export_mode" value="range" id="export_mode_range">
                        Custom Date Range
                    </label>
                </div>

                <!-- Monthly Export Controls -->
                <div id="monthly_controls" style="margin-bottom: 20px; padding: 16px; background: #fefce8; border-radius: 6px; border: 1px solid #fde047;">
                    <h3 style="color: #a16207;">Monthly Selection</h3>
                    <div style="display:flex; gap:20px; margin-bottom:16px; align-items: center;">
                        <div>
                            <label for="selected_month">Month:</label>
                            <select name="selected_month" id="selected_month" required style="margin-left:8px; width: 100px;">';
    $current_month = date('n');
    for ($m=1;$m<=12;$m++) {
        $sel = $m == $current_month ? 'selected' : '';
        echo '<option value="' . $m . '" ' . $sel . '>' . date('F', strtotime("2000-$m-01")) . '</option>';
    }
    echo                            '</select>
                        </div>
                        <div>
                            <label for="selected_year">Year:</label>';
    $current_year = date('Y'); $start_year = 2024;
    echo                            '<select name="selected_year" id="selected_year" required style="margin-left:8px; width: 100px;">';
    for ($y=$start_year;$y<=$current_year;$y++) {
        $sel = $y == $current_year ? 'selected' : '';
        echo "<option value=\"$y\" $sel>$y</option>";
    }
    echo                            '</select>
                        </div>
                    </div>
                    <div class="quick-export-buttons" style="margin-bottom: 16px;">
                        <h4 style="color: #a16207;">Quick Export:</h4>
                        <button type="button" class="button button-secondary" id="export-current-month">Export Current Month</button>
                        <button type="button" class="button button-secondary" id="export-previous-month">Export Previous Month</button>
                    </div>
                </div>

                <!-- Custom Range Controls (initially hidden) -->
                <div id="range_controls" style="display: none; margin-bottom: 20px; padding: 16px; background: #f0f9ff; border-radius: 6px; border: 1px solid #7dd3fc;">
                    <h3 style="color: #0369a1;">Date Range Selection</h3>
                    <div style="margin-bottom: 16px;">
                        <label for="start_date">Start Date:</label>
                        <input type="date" id="start_date" name="start_date" style="margin-left:8px; margin-right:16px;">

                        <label for="end_date">End Date:</label>
                        <input type="date" id="end_date" name="end_date" style="margin-left:8px;">
                    </div>

                    <div class="quick-date-buttons">
                        <button type="button" class="button button-secondary" id="set-range-last-30">Last 30 Days</button>
                        <button type="button" class="button button-secondary" id="set-range-last-60">Last 60 Days</button>
                        <button type="button" class="button button-secondary" id="set-range-last-90">Last 90 Days</button>
                        <button type="button" class="button button-secondary" id="set-range-this-year">This Year</button>
                        <button type="button" class="button button-secondary" id="set-range-from-2025">From Jan 1, 2025</button>
                    </div>
                </div>

                <!-- Forms Selection -->
                <form method="POST" action="' . esc_url(admin_url('admin-post.php?action=export_forms_all_entries')) . '" id="main_export_form">
                    <!-- Hidden fields for monthly export -->
                    <input type="hidden" name="selected_month" id="hidden_selected_month" value="">
                    <input type="hidden" name="selected_year" id="hidden_selected_year" value="">
                    
                    <!-- Hidden fields for custom date range export -->
                    <input type="hidden" name="start_date" id="hidden_start_date" value="">
                    <input type="hidden" name="end_date" id="hidden_end_date" value="">
                    
                    <div style="display:flex; gap:20px; margin-bottom:20px;">
                        <div style="flex:1;">
                            <h3>Available Forms</h3>
                            <select id="available_forms_right" multiple style="width:100%; height:200px; border-radius: 4px; border: 1px solid #d1d5db;">';

    foreach ($forms as $form) {
        if (!in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . esc_attr($form['id']) . '">' . esc_html($form['title']) . '</option>';
        }
    }

    echo        '</select>
                        </div>
                         <div style="flex:1;">
                             <h3>Selected Forms</h3>
                             <select name="selected_forms[]" id="selected_forms_right" multiple style="width:100%; height:200px; border-radius: 4px; border: 1px solid #d1d5db;">';

    foreach ($forms as $form) {
        if (in_array($form['id'], $default_selected_forms)) {
            echo '<option value="' . esc_attr($form['id']) . '" selected>' . esc_html($form['title']) . '</option>';
        }
    }

    echo        '</select>
                        </div>
                    </div>

                    <!-- Separate files option -->
                    <div id="separate_files_option" style="display: none; margin-bottom: 20px; padding: 16px; background: #f0f9ff; border-radius: 6px; border: 1px solid #7dd3fc;">
                        <label style="display: flex; align-items: center; gap: 8px; color: #0369a1; font-weight: 500;">
                            <input type="checkbox" name="separate_files" id="separate_files_checkbox" value="1">
                            Download in separate files, one per form
                        </label>
                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #6b7280;">
                            When multiple forms are selected, each form will be exported as a separate file.
                        </p>
                    </div>

                    <div style="text-align: center; margin-top: 20px;">
                        <button type="submit" class="button button-primary" id="export-button">Export Data</button>
                    </div>
                </form>

                <!-- Export Custom Range Button -->
                <div style="text-align: center; margin-top: 12px;">
                    <button type="button" class="button button-primary" id="export-custom-range" style="display: none;">Export Custom Range</button>
                </div>

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
                        <input style="width: 100px" type="hidden" name="start_date" id="prize_start_date">
                        <input style="width: 100px" type="hidden" name="end_date" id="prize_end_date">
                        <input style="width: 100px" type="hidden" name="selected_month" id="prize_selected_month">
                        <input style="width: 100px" type="hidden" name="selected_year" id="prize_selected_year">
                        <button type="submit" class="button button-primary" id="pick-winners-btn">Pick Prize Drawing Winners</button>
                    </form>
                    
                    <p style="font-size: 12px; color: #6b7280; margin-top: 8px;">
                        <strong>Note:</strong> Uses the same date range as selected above. Winners #1-5 will be selected with weighted entries.
                    </p>
                </div>
        
            </div>
        </div>
    </div>
    <script>
    document.addEventListener("DOMContentLoaded", function () {
        // Form selection functionality
        function moveOptions(sourceId, targetId) {
            const s = document.getElementById(sourceId);
            const t = document.getElementById(targetId);
            if (!s || !t) return;
            Array.from(s.selectedOptions).forEach(opt => t.appendChild(opt));
        }

        // Sync form selections for 2025 export
        function syncFormSelections() {
            const rightSelected = document.getElementById("selected_forms_right");
            const hiddenSelected = document.getElementById("selected_forms_2025_sync");

            if (!rightSelected || !hiddenSelected) return;

            const rightSelectedValues = Array.from(rightSelected.options).map(opt => opt.value);

            Array.from(hiddenSelected.options).forEach(formOpt => {
                if (rightSelectedValues.includes(formOpt.value)) {
                    formOpt.selected = true;
                } else {
                    formOpt.selected = false;
                }
            });
        }

        document.getElementById("available_forms_right")?.addEventListener("dblclick", ()=>{
            moveOptions("available_forms_right", "selected_forms_right");
            setTimeout(function() {
                syncFormSelections();
                updateSeparateFilesOption();
            }, 100);
        });

        document.getElementById("selected_forms_right")?.addEventListener("dblclick", ()=>{
            moveOptions("selected_forms_right", "available_forms_right");
            setTimeout(function() {
                syncFormSelections();
                updateSeparateFilesOption();
            }, 100);
        });

        document.getElementById("selected_forms_right")?.addEventListener("change", function() {
            syncFormSelections();
            updateSeparateFilesOption();
        });

        // Function to show/hide separate files option based on number of selected forms
        function updateSeparateFilesOption() {
            const selectedForms = document.getElementById("selected_forms_right");
            const separateFilesOption = document.getElementById("separate_files_option");
            
            if (!selectedForms || !separateFilesOption) return;
            
            const selectedCount = selectedForms.options.length;
            
            if (selectedCount > 1) {
                separateFilesOption.style.display = "block";
            } else {
                separateFilesOption.style.display = "none";
                // Uncheck the checkbox when hiding
                document.getElementById("separate_files_checkbox").checked = false;
            }
        }

        // Export mode switching
        const monthlyControls = document.getElementById("monthly_controls");
        const rangeControls = document.getElementById("range_controls");
        const exportButton = document.getElementById("export-button");
        const exportCustomRangeButton = document.getElementById("export-custom-range");
        const mainForm = document.getElementById("main_export_form");

        function toggleExportMode() {
            const isMonthMode = document.getElementById("export_mode_month").checked;

            if (isMonthMode) {
                monthlyControls.style.display = "block";
                rangeControls.style.display = "none";
                exportButton.style.display = "inline-block";
                exportCustomRangeButton.style.display = "none";
                exportButton.textContent = "Export";
            } else {
                monthlyControls.style.display = "none";
                rangeControls.style.display = "block";
                exportButton.style.display = "none";
                exportCustomRangeButton.style.display = "inline-block";
                exportCustomRangeButton.textContent = "Export Custom Range";
            }
        }

        document.getElementById("export_mode_month")?.addEventListener("change", function() {
            toggleExportMode();
            syncPrizeDrawingDates();
        });
        document.getElementById("export_mode_range")?.addEventListener("change", function() {
            toggleExportMode();
            syncPrizeDrawingDates();
        });

        // Ensure all forms are selected before submitting
        function ensureFormsSelected() {
            const rightSelected = document.getElementById("selected_forms_right");
            if (rightSelected) {
                Array.from(rightSelected.options).forEach(option => {
                    option.selected = true;
                });
            }
        }

        mainForm?.addEventListener("submit", function(e) {
            ensureFormsSelected();
            
            // Sync the appropriate fields based on export mode
            if (document.getElementById("export_mode_month").checked) {
                // Monthly mode - sync month and year
                document.getElementById("hidden_selected_month").value = document.getElementById("selected_month").value;
                document.getElementById("hidden_selected_year").value = document.getElementById("selected_year").value;
                // Clear custom date range fields
                document.getElementById("hidden_start_date").value = "";
                document.getElementById("hidden_end_date").value = "";
            } else {
                // Custom range mode - sync start and end dates
                document.getElementById("hidden_start_date").value = document.getElementById("start_date").value;
                document.getElementById("hidden_end_date").value = document.getElementById("end_date").value;
                // Clear monthly fields
                document.getElementById("hidden_selected_month").value = "";
                document.getElementById("hidden_selected_year").value = "";
            }
        });

        // Quick month export functions
        function quickSubmit(month, year) {
            ensureFormsSelected();

            // Update hidden form values for monthly export
            document.getElementById("hidden_selected_month").value = month;
            document.getElementById("hidden_selected_year").value = year;
            // Clear custom date range fields
            document.getElementById("hidden_start_date").value = "";
            document.getElementById("hidden_end_date").value = "";

            // Also update visible selects for user feedback
            document.getElementById("selected_month").value = month;
            document.getElementById("selected_year").value = year;

            // Submit the form
            mainForm.submit();
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

        // Custom range export
        document.getElementById("export-custom-range")?.addEventListener("click", function() {
            ensureFormsSelected();

            // Sync custom date range fields and clear monthly fields
            document.getElementById("hidden_start_date").value = document.getElementById("start_date").value;
            document.getElementById("hidden_end_date").value = document.getElementById("end_date").value;
            document.getElementById("hidden_selected_month").value = "";
            document.getElementById("hidden_selected_year").value = "";

            // Submit the form - the handler will detect range export
            mainForm.submit();
        });

        // Date range controls for custom range mode
        function setDateRange(startDate, endDate) {
            document.getElementById("start_date").value = startDate;
            document.getElementById("end_date").value = endDate;
            syncPrizeDrawingDates();
        }

function syncPrizeDrawingDates() {
    const isMonthMode = document.getElementById("export_mode_month").checked;
    
    if (isMonthMode) {
        // Monthly mode - calculate date range from month/year
        const selectedMonth = parseInt(document.getElementById("selected_month").value, 10);
        const selectedYear = parseInt(document.getElementById("selected_year").value, 10);
        
        if (selectedMonth && selectedYear) {
            // Ensure the month has two digits
            const monthStr = selectedMonth.toString().padStart(2, "0");
            
            // Format YYYY-MM-DD for PHP
            const startDate = `${selectedYear}-${monthStr}-01`;
            
            // Calculate last day of the month
            const lastDay = new Date(selectedYear, selectedMonth, 0).getDate();
            const endDate = `${selectedYear}-${monthStr}-${lastDay}`;
            
            document.getElementById("prize_start_date").value = startDate;
            document.getElementById("prize_end_date").value = endDate;
            document.getElementById("prize_selected_month").value = selectedMonth;
            document.getElementById("prize_selected_year").value = selectedYear;
        }
    } else {
        // Custom range mode - use date inputs
        const startDateInput = document.getElementById("start_date").value;
        const endDateInput = document.getElementById("end_date").value;
        
        // The input type="date" uses YYYY-MM-DD format
        document.getElementById("prize_start_date").value = startDateInput;
        document.getElementById("prize_end_date").value = endDateInput;
        
        // Clear monthly fields in custom range mode
        document.getElementById("prize_selected_month").value = "";
        document.getElementById("prize_selected_year").value = "";
    }
}



        document.getElementById("start_date")?.addEventListener("change", syncPrizeDrawingDates);
        document.getElementById("end_date")?.addEventListener("change", syncPrizeDrawingDates);
        document.getElementById("selected_month")?.addEventListener("change", syncPrizeDrawingDates);
        document.getElementById("selected_year")?.addEventListener("change", syncPrizeDrawingDates);

        document.getElementById("set-range-last-30")?.addEventListener("click", ()=>{
            const endDate = new Date().toISOString().split("T")[0];
            const startDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split("T")[0];
            setDateRange(startDate, endDate);
        });

        document.getElementById("set-range-last-60")?.addEventListener("click", ()=>{
            const endDate = new Date().toISOString().split("T")[0];
            const startDate = new Date(Date.now() - 60 * 24 * 60 * 60 * 1000).toISOString().split("T")[0];
            setDateRange(startDate, endDate);
        });

        document.getElementById("set-range-last-90")?.addEventListener("click", ()=>{
            const endDate = new Date().toISOString().split("T")[0];
            const startDate = new Date(Date.now() - 90 * 24 * 60 * 60 * 1000).toISOString().split("T")[0];
            setDateRange(startDate, endDate);
        });

        document.getElementById("set-range-this-year")?.addEventListener("click", ()=>{
            const currentYear = new Date().getFullYear();
            const startDate = currentYear + "-01-01";
            const endDate = new Date().toISOString().split("T")[0];
            setDateRange(startDate, endDate);
        });

        document.getElementById("set-range-from-2025")?.addEventListener("click", ()=>{
            setDateRange("2025-01-01", new Date().toISOString().split("T")[0]);
        });

        // Initialize
        toggleExportMode();
        const currentYear = new Date().getFullYear();
        setDateRange(currentYear + "-01-01", new Date().toISOString().split("T")[0]);
        syncPrizeDrawingDates();
        updateSeparateFilesOption();
    });
    </script>
    ';

    // End of function
}
