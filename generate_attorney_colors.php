<?php
/**
 * Dynamic Attorney Color System
 * Generates attorney colors based on actual database data
 */

require_once 'config.php';

// Function to get all attorneys from database
function getAllAttorneys() {
    global $conn;
    
    $attorneys = [];
    $stmt = $conn->prepare("SELECT id, name, user_type FROM user_form WHERE user_type IN ('attorney', 'admin') ORDER BY id ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $attorneys[] = $row;
    }
    
    return $attorneys;
}

// Function to generate color palette
function generateColorPalette() {
    return [
        ['bg' => '#51cf66', 'border' => '#40c057', 'name' => 'Light Green'],
        ['bg' => '#74c0fc', 'border' => '#4dabf7', 'name' => 'Light Blue'],
        ['bg' => '#ff6b6b', 'border' => '#ff5252', 'name' => 'Light Red'],
        ['bg' => '#ffd43b', 'border' => '#fcc419', 'name' => 'Light Orange'],
        ['bg' => '#da77f2', 'border' => '#cc5de8', 'name' => 'Light Violet'],
        ['bg' => '#ffa8a8', 'border' => '#ff8787', 'name' => 'Light Pink'],
        ['bg' => '#69db7c', 'border' => '#51cf66', 'name' => 'Bright Green'],
        ['bg' => '#4dabf7', 'border' => '#339af0', 'name' => 'Bright Blue'],
        ['bg' => '#e599f7', 'border' => '#da77f2', 'name' => 'Bright Violet'],
        ['bg' => '#ffb3bf', 'border' => '#ffa8a8', 'name' => 'Bright Pink'],
        ['bg' => '#96f2d7', 'border' => '#69db7c', 'name' => 'Mint Green'],
        ['bg' => '#a5d8ff', 'border' => '#74c0fc', 'name' => 'Sky Blue'],
        ['bg' => '#ffec99', 'border' => '#ffd43b', 'name' => 'Bright Yellow'],
        ['bg' => '#d0bfff', 'border' => '#da77f2', 'name' => 'Lavender'],
        ['bg' => '#ffc9c9', 'border' => '#ffa8a8', 'name' => 'Rose Pink']
    ];
}

// Function to generate attorney color mapping
function generateAttorneyColorMapping() {
    $attorneys = getAllAttorneys();
    $colorPalette = generateColorPalette();
    $colorMapping = [];
    
    foreach ($attorneys as $index => $attorney) {
        $colorIndex = $index % count($colorPalette);
        $colorMapping[$attorney['name']] = [
            'id' => $attorney['id'],
            'user_type' => $attorney['user_type'],
            'bg' => $colorPalette[$colorIndex]['bg'],
            'border' => $colorPalette[$colorIndex]['border'],
            'name' => $colorPalette[$colorIndex]['name']
        ];
    }
    
    return $colorMapping;
}

// Generate the color mapping
$attorneyColors = generateAttorneyColorMapping();

// Output as JavaScript
header('Content-Type: application/javascript');
echo "// Dynamic Attorney Colors - Generated from Database\n";
echo "// Last updated: " . date('Y-m-d H:i:s') . "\n\n";
echo "const DYNAMIC_ATTORNEY_COLORS = " . json_encode($attorneyColors, JSON_PRETTY_PRINT) . ";\n\n";

echo "// Admin color (consistent)\n";
echo "const ADMIN_COLOR = { bg: '#ff6b6b', border: '#ff5252', name: 'Admin Red' };\n\n";

echo "/**\n";
echo " * Get attorney color based on attorney name (Dynamic Version)\n";
echo " * @param {string} attorneyName - The name of the attorney\n";
echo " * @param {string} userType - The user type (admin, attorney, etc.)\n";
echo " * @returns {Object} Color object with bg, border, and name properties\n";
echo " */\n";
echo "function getDynamicAttorneyColor(attorneyName, userType = 'attorney') {\n";
echo "    // Check if it's an admin\n";
echo "    if (userType === 'admin') {\n";
echo "        return ADMIN_COLOR;\n";
echo "    }\n";
echo "    \n";
echo "    // Check if attorney has color assignment from database\n";
echo "    if (DYNAMIC_ATTORNEY_COLORS[attorneyName]) {\n";
echo "        return DYNAMIC_ATTORNEY_COLORS[attorneyName];\n";
echo "    }\n";
echo "    \n";
echo "    // Default color for unknown attorneys\n";
echo "    return { bg: '#ffd43b', border: '#fcc419', name: 'Default Orange' };\n";
echo "}\n\n";

echo "/**\n";
echo " * Apply attorney colors to a calendar event element (Dynamic Version)\n";
echo " * @param {HTMLElement} element - The calendar event element\n";
echo " * @param {string} attorneyName - The attorney name\n";
echo " * @param {string} userType - The user type\n";
echo " */\n";
echo "function applyDynamicAttorneyColors(element, attorneyName, userType = 'attorney') {\n";
echo "    const colors = getDynamicAttorneyColor(attorneyName, userType);\n";
echo "    \n";
echo "    element.style.backgroundColor = colors.bg;\n";
echo "    element.style.borderColor = colors.border;\n";
echo "    element.style.color = '#333';\n";
echo "    element.style.fontWeight = '500';\n";
echo "    element.style.borderWidth = '2px';\n";
echo "    element.style.borderStyle = 'solid';\n";
echo "    \n";
echo "    console.log('Applied ' + colors.name + ' colors to ' + attorneyName + ':', colors.bg);\n";
echo "}\n\n";

echo "// Export functions for use in other files\n";
echo "if (typeof module !== 'undefined' && module.exports) {\n";
echo "    module.exports = {\n";
echo "        getDynamicAttorneyColor,\n";
echo "        applyDynamicAttorneyColors,\n";
echo "        DYNAMIC_ATTORNEY_COLORS,\n";
echo "        ADMIN_COLOR\n";
echo "    };\n";
echo "}\n";
?>
