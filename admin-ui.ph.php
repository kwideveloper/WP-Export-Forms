<?php
// Function to generate the admin page with the enhanced UI
function export_forms_page() {
    global $wpdb;

    // Estilos embebidos
    echo '<style>
        #available_forms, #selected_forms {
            width: 100%;
            height: 300px;
            border: 1px solid #ccc;
            border-radius: 5px;
            padding: 5px;
			color: red;
        }
        #add_form, #remove_form {
            background-color: #0073aa;
            color: #fff;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 3px;
        }
        #add_form:hover, #remove_form:hover {
            background-color: #005177;
        }
    </style>';

    // Generar la interfaz de usuario
    echo '<div class="wrap">';
    echo '<h1>Export Forms</h1>';
    echo '<p>Select the month, year, and forms to export the combined forms data.</p>';
    echo '<form method="POST" action="' . admin_url('admin-post.php?action=export_forms_custom_month') . '">';
    echo '<div style="display: flex; gap: 20px;">';

    // Left column: Available forms
    echo '<div style="flex: 1;">';
    echo '<h3>Available Forms</h3>';
    echo '<select id="available_forms" multiple>';
    foreach ($forms as $form) {
        echo '<option value="' . $form['id'] . '">' . $form['title'] . '</option>';
    }
    echo '</select>';
    echo '</div>';

    // Middle column: Buttons
    echo '<div style="flex: 0.2; display: flex; flex-direction: column; justify-content: center;">';
    echo '<button type="button" id="add_form">&gt;&gt;</button>';
    echo '<button type="button" id="remove_form">&lt;&lt;</button>';
    echo '</div>';

    // Right column: Selected forms
    echo '<div style="flex: 1;">';
    echo '<h3>Selected Forms</h3>';
    echo '<select name="selected_forms[]" id="selected_forms" multiple required>';
    foreach ($forms as $form) {
        $selected = in_array($form['id'], $default_selected_forms) ? 'selected' : '';
        echo '<option value="' . $form['id'] . '" ' . $selected . '>' . $form['title'] . '</option>';
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
    echo '<select name="selected_year" id="selected_year" required>';
    for ($year = date('Y') - 10; $year <= date('Y'); $year++) {
        $selected = ($year == date('Y')) ? 'selected' : ''; // Set current year as default
        echo '<option value="' . $year . '" ' . $selected . '>' . $year . '</option>';
    }
    echo '</select>';
    echo '<br><br><button type="submit" class="button button-primary">Export Selected Month</button>';
    echo '</form>';
    echo '</div>';

    // JavaScript for moving forms between lists
    echo '<script>
        document.getElementById("add_form").addEventListener("click", function() {
            moveOptions("available_forms", "selected_forms");
        });
        document.getElementById("remove_form").addEventListener("click", function() {
            moveOptions("selected_forms", "available_forms");
        });
        function moveOptions(sourceId, targetId) {
            const source = document.getElementById(sourceId);
            const target = document.getElementById(targetId);
            Array.from(source.selectedOptions).forEach(option => {
                target.appendChild(option);
            });
        }
    </script>';
}
