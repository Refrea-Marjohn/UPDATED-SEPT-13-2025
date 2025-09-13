<?php
/**
 * Refresh Attorney Colors
 * This file can be called to refresh the attorney color cache
 */

require_once 'config.php';

// Function to clear any cached data (if you implement caching later)
function clearColorCache() {
    // For now, we don't have caching, but this is where you'd clear it
    return true;
}

// Function to regenerate colors
function refreshAttorneyColors() {
    clearColorCache();
    
    // The colors will be regenerated automatically when the schedule pages load
    // because they call generate_attorney_colors.php directly
    
    return true;
}

// If this file is called directly, refresh the colors
if (basename($_SERVER['PHP_SELF']) == 'refresh_attorney_colors.php') {
    refreshAttorneyColors();
    echo "Attorney colors refreshed successfully!";
}
?>
