<?php
/**
 * Attorney Color Update Helper
 * Run this script to update attorney colors based on current database
 */

require_once 'config.php';

echo "=== ATTORNEY COLOR UPDATE HELPER ===\n\n";

// Get current attorneys from database
$stmt = $conn->prepare("SELECT id, name, user_type FROM user_form WHERE user_type IN ('attorney', 'admin') ORDER BY id ASC");
$stmt->execute();
$result = $stmt->get_result();

$attorneys = [];
while ($row = $result->fetch_assoc()) {
    $attorneys[] = $row;
}

echo "Current Attorneys in Database:\n";
foreach ($attorneys as $attorney) {
    echo "- {$attorney['name']} ({$attorney['user_type']}) - ID: {$attorney['id']}\n";
}

echo "\n=== COLOR ASSIGNMENTS ===\n";

$colors = [
    ['bg' => '#51cf66', 'border' => '#40c057', 'name' => 'Light Green'],
    ['bg' => '#74c0fc', 'border' => '#4dabf7', 'name' => 'Light Blue'],
    ['bg' => '#ff6b6b', 'border' => '#ff5252', 'name' => 'Light Red'],
    ['bg' => '#ffd43b', 'border' => '#fcc419', 'name' => 'Light Orange'],
    ['bg' => '#da77f2', 'border' => '#cc5de8', 'name' => 'Light Violet'],
    ['bg' => '#ffa8a8', 'border' => '#ff8787', 'name' => 'Light Pink'],
    ['bg' => '#69db7c', 'border' => '#51cf66', 'name' => 'Bright Green'],
    ['bg' => '#4dabf7', 'border' => '#339af0', 'name' => 'Bright Blue'],
    ['bg' => '#e599f7', 'border' => '#da77f2', 'name' => 'Bright Violet'],
    ['bg' => '#ffb3bf', 'border' => '#ffa8a8', 'name' => 'Bright Pink']
];

foreach ($attorneys as $index => $attorney) {
    $colorIndex = $index % count($colors);
    $color = $colors[$colorIndex];
    echo "{$attorney['name']} → {$color['name']} ({$color['bg']})\n";
}

echo "\n=== TO UPDATE COLORS ===\n";
echo "1. Edit assets/js/attorney-colors.js\n";
echo "2. Update the ATTORNEY_COLORS object with the names above\n";
echo "3. Save the file\n";
echo "4. Refresh your schedule pages\n\n";

echo "=== EXAMPLE CODE FOR attorney-colors.js ===\n";
echo "const ATTORNEY_COLORS = {\n";
foreach ($attorneys as $index => $attorney) {
    $colorIndex = $index % count($colors);
    $color = $colors[$colorIndex];
    echo "    '{$attorney['name']}': { bg: '{$color['bg']}', border: '{$color['border']}', name: '{$color['name']}' },\n";
}
echo "    // Additional colors for new attorneys...\n";
echo "};\n\n";

echo "Done! Copy the example code above to update your attorney-colors.js file.\n";
?>
