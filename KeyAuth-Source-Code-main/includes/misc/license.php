<?php

namespace misc\license;

use misc\etc;
use misc\cache;
use misc\user;
use misc\mysql;
use misc\token;

/**
 * Generate license with custom mask
 * @param string $mask - License pattern (use * for random chars)
 * @param string $customKey - Optional custom key (overrides mask)
 * @param int $int - Character type: 1=alphanumeric, 2=uppercase, 3=lowercase
 */
function license_masking($mask, $int = null, $customKey = null) 
{
    // If custom key provided, use it directly
    if (!empty($customKey)) {
        return $customKey;
    }

    $mask_arr = str_split($mask);
    $size_of_mask = count($mask_arr);
    
    for ($i = 0; $i < $size_of_mask; $i++) {
        if ($mask_arr[$i] === '*') {
            if (isset($_POST['lowercaseLetters']) && $_POST['lowercaseLetters'] == 'on' && isset($_POST['capitalLetters']) && $_POST['capitalLetters'] == 'on') {
                $mask_arr[$i] = etc\random_string_gen(1);
            } elseif (isset($_POST['lowercaseLetters']) && $_POST['lowercaseLetters'] == 'on') {
                $mask_arr[$i] = etc\random_string_lower(1);
            } elseif (isset($_POST['capitalLetters']) && $_POST['capitalLetters'] == 'on') {
                $mask_arr[$i] = etc\random_string_upper(1);
            } else {
                if (isset($int)) {
                    if ($int === "1") {
                        $mask_arr[$i] = etc\random_string_gen(1);
                    } elseif ($int === "2") {
                        $mask_arr[$i] = etc\random_string_upper(1);
                    } elseif ($int === "3") {
                        $mask_arr[$i] = etc\random_string_lower(1);
                    }
                } else {
                    $mask_arr[$i] = etc\random_string_gen(1);
                }
            }
        }
    }
    return implode('', $mask_arr);
}

/**
 * Create License - UNLIMITED VERSION
 * @param int $amount - Number of licenses (NO LIMIT)
 * @param string $mask - License mask pattern
 * @param int $duration - License duration
 * @param int $level - License level
 * @param string $note - License note
 * @param int $expiry - Expiry time
 * @param string $secret - App secret
 * @param string $owner - License owner
 * @param string $character - Character type
 * @param string $customKey - CUSTOM KEY (NEW FEATURE)
 */
function createLicense($amount, $mask, $duration, $level, $note, $expiry = null, $secret = null, $owner = null, $character = null, $customKey = null)
{
    $amount = etc\sanitize($amount);
    $mask = etc\sanitize($mask);
    $duration = etc\sanitize($duration);
    $level = etc\sanitize($level);
    $note = etc\sanitize($note);
    $expiry = etc\sanitize($expiry);
    $secret = etc\sanitize($secret);
    $letters = etc\sanitize($character);
    $customKey = etc\sanitize($customKey);

    // 🔥 REMOVED: $amount > 100 limit
    // Ab unlimited licenses bana sakte hain
    
    if (!isset($amount) || $amount < 1) {
        $amount = 1;
    }
    
    if (!is_numeric($level)) {
        $level = 1;
    }
    
    if (is_null($expiry)) {
        $expiry = 86400; // 1 day default
    }
    
    $duration = $duration * $expiry;
    
    // Check for duplicate custom key
    if ($amount > 1 && !empty($customKey)) {
        return 'dupe_custom_key'; // Can't use same custom key for multiple licenses
    }

    // Check if custom key already exists
    if (!empty($customKey)) {
        $check = mysql\query("SELECT 1 FROM `keys` WHERE `key` = ? AND `app` = ?", [$customKey, $secret ?? $_SESSION['app']]);
        if ($check->num_rows > 0) {
            return 'key_already_exists';
        }
    }

    // ===== RESELLER SECTION - REMOVED LIMITS =====
    switch ($_SESSION['role']) {
        case 'tester':
            // 🔥 REMOVED: 10 key limit for tester
            // Ab tester unlimited bana sakta hai
            $mask = "KEYAUTH-" . $mask;
            break;
            
        case 'Reseller':
            // 🔥 REMOVED: Balance check and limits
            // Ab reseller unlimited bana sakta hai
            if ($amount < 0) {
                return 'no_negative';
            }
            // Balance check REMOVED - Unlimited
            break;
            
        case 'seller':
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
            break;
    }
    
    if (!is_null($secret)) {
        cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
    }

    $licenses = array();

    for ($i = 0; $i < $amount; $i++) {
        // Generate license - if customKey provided and i==0 use it, else generate
        if (!empty($customKey) && $i === 0) {
            $license = $customKey;
        } else {
            $license = license_masking($mask, $letters, null);
        }
        
        if (token\ModifyUserToken($license, "License", null, null, $secret ?? $_SESSION["app"]) === "failed") {
            return "failure";
        }
        
        $query = mysql\query(
            "INSERT INTO `keys` (`key`, `note`, `expires`, `status`, `level`, `genby`, `gendate`, `app`) 
             VALUES (?, NULLIF(?, ''), ?, 'Not Used', ?, ?, ?, ?)",
            [$license, $note, $duration, $level, $owner ?? $_SESSION['username'], time(), $secret ?? $_SESSION['app']]
        );
        
        $licenses[] = $license;
    }
    
    return $licenses;
}

/**
 * CREATE CUSTOM KEY - NEW FUNCTION
 * @param string $customKey - Custom license key
 * @param int $duration - Duration
 * @param int $level - Level
 * @param string $note - Note
 * @param string $secret - App secret
 */
function createCustomKey($customKey, $duration, $level, $note, $secret = null)
{
    $customKey = etc\sanitize($customKey);
    $duration = etc\sanitize($duration);
    $level = etc\sanitize($level);
    $note = etc\sanitize($note);
    $secret = etc\sanitize($secret);
    
    // Validate custom key
    if (empty($customKey)) {
        return 'empty_key';
    }
    
    if (strlen($customKey) < 4) {
        return 'key_too_short';
    }
    
    // Check if key exists
    $check = mysql\query("SELECT 1 FROM `keys` WHERE `key` = ? AND `app` = ?", [$customKey, $secret ?? $_SESSION['app']]);
    if ($check->num_rows > 0) {
        return 'key_exists';
    }
    
    // Create license with custom key
    return createLicense(
        1,                          // amount
        $customKey,                 // mask (will be overridden)
        $duration,                  // duration
        $level,                     // level
        $note,                      // note
        null,                       // expiry
        $secret,                    // secret
        null,                       // owner
        null,                       // character
        $customKey                  // customKey (NEW PARAMETER)
    );
}

// ========== REST OF THE FUNCTIONS (UNCHANGED) ==========

function addTime($time, $expiry, $secret = null)
{
    $time = etc\sanitize($time);
    $expiry = etc\sanitize($expiry);

    $time = $time * $expiry;
    $query = mysql\query("UPDATE `keys` SET `expires` = `expires`+? WHERE `app` = ? AND `status` = 'Not Used'",[$time, $secret ?? $_SESSION['app']]);
    if ($query->affected_rows > 0) {
        if ($_SESSION['role'] == "seller" || !is_null($secret)) {
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
        }
        return 'success';
    } else {
        return 'failure';
    }
}

function deleteAll($secret = null)
{
    $query = mysql\query("DELETE FROM `keys` WHERE `app` = ?",[$secret ?? $_SESSION['app']]);
    if ($query->affected_rows > 0) {
        $query = mysql\query("DELETE FROM `tokens` WHERE `app` = ? AND `type` = ?",[$secret ?? $_SESSION['app'], "license"]);
        if ($query->affected_rows > 0) { 
            cache\purgePattern('KeyAuthUserTokens:' . ($secret ?? $_SESSION['app'])); 
        }
        if ($_SESSION['role'] == "seller" || !is_null($secret)) {
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
        }
        return 'success';
    } else {
        return 'failure';
    }
}

function deleteAllUnused($secret = null)
{
    $query = mysql\query("DELETE FROM `keys` WHERE `app` = ? AND `status` = 'Not Used'",[$secret ?? $_SESSION['app']]);
    if ($query->affected_rows > 0) {
        $query = mysql\query("DELETE FROM `tokens` WHERE `app` = ? AND `status` = ? AND `type` = ?",[$secret ?? $_SESSION['app'], "Not Used", "license"]);
        if ($query->affected_rows > 0) { 
            cache\purgePattern('KeyAuthUserTokens:' . ($secret ?? $_SESSION['app'])); 
        }
        if ($_SESSION['role'] == "seller" || !is_null($secret)) {
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
        }
        return 'success';
    } else {
        return 'failure';
    }
}

function deleteAllUsed($secret = null)
{
    $query = mysql\query("DELETE FROM `keys` WHERE `app` = ? AND `status` = 'Used'",[$secret ?? $_SESSION['app']]);
    if ($query->affected_rows > 0) {
        if ($_SESSION['role'] == "seller" || !is_null($secret)) {
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
        }
        return 'success';
    } else {
        return 'failure';
    }
}

function deleteSingular($key, $userToo, $secret = null)
{
    $key = etc\sanitize($key);
    $userToo = etc\sanitize($userToo);

    if ($_SESSION['role'] == "Reseller") {
        $query = mysql\query("SELECT 1 FROM `keys` WHERE `app` = ? AND `key` = ? AND `genby` = ?",[$secret ?? $_SESSION['app'], $key, $_SESSION['username']]);
        if ($query->num_rows < 1) {
            return 'nope';
        }
    }

    if ($userToo) {
        $query = mysql\query("SELECT `usedby` FROM `keys` WHERE `app` = ? AND `key` = ?",[$secret ?? $_SESSION['app'], $key]);
        $row = mysqli_fetch_array($query->result);
        $usedby = $row['usedby'];
        user\deleteSingular($usedby, $secret);
    }

    $query = mysql\query("DELETE FROM `subs` WHERE `app` = ? AND `key` = ?",[$secret ?? $_SESSION['app'], $key]);
    $query = mysql\query("DELETE FROM `tokens` WHERE `app` = ? AND `assigned` = ?", [$secret ?? $_SESSION['app'], $key]);
    $query = mysql\query("DELETE FROM `keys` WHERE `app` = ? AND `key` = ?",[$secret ?? $_SESSION['app'], $key]);
    
    if ($query->affected_rows > 0) {
        cache\purgePattern('KeyAuthUserTokens:' . ($secret ?? $_SESSION['app'])); 
        if ($_SESSION['role'] == "seller" || !is_null($secret)) {
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
            cache\purge('KeyAuthKey:' . ($secret ?? $_SESSION['app']) . ':' . $key);
        }
        return 'success';
    } else {
        return 'failure';
    }
}

function deleteMultiple($keys, $userToo, $secret = null) {
    $keys = explode(', ', $keys);
    $userToo = etc\sanitize($userToo);

    foreach ($keys as $key) {
        $key = etc\sanitize(trim($key));

        if ($_SESSION['role'] == "Reseller") {
            $query = mysql\query("SELECT 1 FROM `keys` WHERE `app` = ? AND `key` = ? AND `genby` = ?",[$secret ?? $_SESSION['app'], $key, $_SESSION['username']]);
            if ($query->num_rows < 1) {
                return 'nope';
            }
        }

        if ($userToo) {
            $query = mysql\query("SELECT `usedby` FROM `keys` WHERE `app` = ? AND `key` = ?",[$secret ?? $_SESSION['app'], $key]);
            $row = mysqli_fetch_array($query->result);
            $usedby = $row['usedby'];
            user\deleteSingular($usedby, $secret);
        }

        $query = mysql\query("DELETE FROM `subs` WHERE `app` = ? AND `key` = ?",[$secret ?? $_SESSION['app'], $key]);
        $query = mysql\query("DELETE FROM `tokens` WHERE `app` = ? AND `assigned` = ?", [$secret ?? $_SESSION['app'], $key]);
        $query = mysql\query("DELETE FROM `keys` WHERE `app` = ? AND `key` = ?",[$secret ?? $_SESSION['app'], $key]);
        
        if ($query->affected_rows > 0) {
            cache\purgePattern('KeyAuthUserTokens:' . ($secret ?? $_SESSION['app'])); 
            if ($_SESSION['role'] == "seller" || !is_null($secret)) {
                cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
                cache\purge('KeyAuthKey:' . ($secret ?? $_SESSION['app']) . ':' . $key);
            }
        } else {
            return 'failure';
        }
    }
    return 'success';
}

function ban($key, $reason, $userToo, $secret = null)
{
    $key = etc\sanitize($key);
    $reason = etc\sanitize($reason);
    $userToo = etc\sanitize($userToo);

    if ($_SESSION['role'] == "Reseller") {
        $query = mysql\query("SELECT 1 FROM `keys` WHERE `app` = ? AND `key` = ? AND `genby` = ?", [$secret ?? $_SESSION['app'], $key, $_SESSION['username']]);
        if ($query->num_rows === 0) {
            return 'nope';
        }
    }

    if ($userToo) {
        $query = mysql\query("SELECT `usedby` FROM `keys` WHERE `app` = ? AND `key` = ?", [$secret ?? $_SESSION['app'], $key]);
        $row = mysqli_fetch_array($query->result);
        $usedby = $row['usedby'];
        user\ban($usedby, $reason, $secret);
    }

    $query = mysql\query("UPDATE `keys` SET `banned` = ?, `status` = 'Banned' WHERE `app` = ? AND `key` = ?",[$reason, $secret ?? $_SESSION['app'], $key]);
    if ($query->affected_rows > 0) {
        if ($_SESSION['role'] == "seller" || !is_null($secret)) {
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
        }

        $query = mysql\query("UPDATE `tokens` SET `banned` = ?, `reason` = NULL WHERE `app` = ? AND `assigned` = ? AND `type` = ?", [1, $secret ?? $_SESSION['app'], $key, "license"]);
        if ($query->affected_rows > 0) { 
            cache\purgePattern('KeyAuthUserTokens:' . ($secret ?? $_SESSION['app'])); 
        } else { 
            return 'failure'; 
        }                         
        return 'success';
    } else {
        return 'failure';
    }
}

function unban($key, $secret = null)
{
    $key = etc\sanitize($key);

    if ($_SESSION['role'] == "Reseller") {
        $query = mysql\query("SELECT 1 FROM `keys` WHERE `app` = ? AND `key` = ? AND `genby` = ?",[$secret ?? $_SESSION['app'], $key, $_SESSION['username']]);
        if ($query->num_rows === 0) {
            return 'nope';
        }
    }

    $status = "Not Used";
    $query = mysql\query("SELECT `usedby` FROM `keys` WHERE `app` = ? AND `key` = ?",[$secret ?? $_SESSION['app'], $key]);
    $row = mysqli_fetch_array($query->result);
    if (!is_null($row['usedby'])) {
        $status = "Used";       
        user\unban($row['usedby'], $secret);
    }

    $query = mysql\query("UPDATE `keys` SET `banned` = NULL, `status` = ? WHERE `app` = ? AND `key` = ?",[$status, $secret ?? $_SESSION['app'], $key]);
    if ($query->affected_rows > 0) {
        if ($_SESSION['role'] == "seller" || !is_null($secret)) {
            cache\purge('KeyAuthKeys:' . ($secret ?? $_SESSION['app']));
        }
        
        $query = mysql\query("UPDATE `tokens` SET `banned` = 0, `reason` = NULL WHERE `app` = ? AND `assigned` = ? AND `type` = ?", [$secret ?? $_SESSION['app'], $key, "license"]);
        if ($query->affected_rows > 0) { 
            cache\purgePattern('KeyAuthUserTokens:' . ($secret ?? $_SESSION['app']));    
        } else { 
            return 'failure'; 
        }
        return 'success';
    } else {
        return 'failure';
    }
}

// NEW: Get all licenses (for admin)
function getAllLicenses($secret = null)
{
    $query = mysql\query("SELECT * FROM `keys` WHERE `app` = ? ORDER BY `id` DESC", [$secret ?? $_SESSION['app']]);
    return $query->result;
}

// NEW: Count total licenses
function countLicenses($secret = null)
{
    $query = mysql\query("SELECT COUNT(*) as total FROM `keys` WHERE `app` = ?", [$secret ?? $_SESSION['app']]);
    $row = mysqli_fetch_array($query->result);
    return $row['total'] ?? 0;
}

?>