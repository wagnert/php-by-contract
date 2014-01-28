<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 */

// The constants for our annotations
define('PBC_KEYWORD_PRE', '@requires');
define('PBC_KEYWORD_POST', '@ensures');
define('PBC_KEYWORD_INVARIANT', '@invariant');

// Some keywords we need for our constructed code
define('PBC_KEYWORD_OLD', '$pbcOld');
define('PBC_KEYWORD_RESULT', '$pbcResult');
define('PBC_CONTRACT_CONTEXT', '$pbcOngoingContract');
define('PBC_MARK_CONTRACT_ENTRY', '$pbcContractEntry');
define('PBC_ATTRIBUTE_STORAGE', 'pbcAttributes');
define('PBC_FAILURE_VARIABLE', '$pbcFailureMessage');
define('PBC_CLOSURE_VARIABLE', '$pbcFunctionClosure');
define('PBC_DIR_SUBSTITUTE', 'PBC_DIR_SUBSTITUTE');
define('PBC_FILE_SUBSTITUTE', 'PBC_FILE_SUBSTITUTE');

define('PBC_CLASS_INVARIANT_NAME', 'pbcClassInvariant');
define('PBC_ORIGINAL_FUNCTION_SUFFIX', 'PBCOriginal');

// Will be used as placeholder for code procession
define('PBC_PROCESSING_PLACEHOLDER', '/* PBC_PROCESSING_PLACEHOLDER ');
define('PBC_PRECONDITION_PLACEHOLDER', '/* PBC_PRECONDITION_PLACEHOLDER ');
define('PBC_POSTCONDITION_PLACEHOLDER', '/* PBC_POSTCONDITION_PLACEHOLDER ');
define('PBC_INVARIANT_PLACEHOLDER', '/* PBC_INVARIANT_PLACEHOLDER ');
define('PBC_OLD_SETUP_PLACEHOLDER', '/* PBC_OLD_SETUP_PLACEHOLDER ');
define('PBC_FUNCTION_HOOK_PLACEHOLDER', '/* PBC_FUNCTION_HOOK_PLACEHOLDER ');
define('PBC_METHOD_INJECT_PLACEHOLDER', '/* PBC_METHOD_INJECT_PLACEHOLDER ');
define('PBC_PLACEHOLDER_CLOSE', ' */');
define('PBC_ORIGINAL_PATH_HINT', 'PBC_ORIGINAL_PATH_HINT');

// We might not have a PHP > 5.3 on our hands.
// To avoid parser errors we will define used constants here
if (!defined('T_TRAIT')) {

    define('T_TRAIT', 355);
}
