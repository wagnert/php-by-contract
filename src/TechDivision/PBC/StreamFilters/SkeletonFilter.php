<?php

/**
 * TechDivision\PBC\StreamFilters\SkeletonFilter
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 */

namespace TechDivision\PBC\StreamFilters;

use TechDivision\PBC\Entities\Definitions\FunctionDefinition;
use TechDivision\PBC\Exceptions\GeneratorException;

/**
 * @package     TechDivision\PBC
 * @subpackage  StreamFilters
 * @copyright   Copyright (c) 2013 <info@techdivision.com> - TechDivision GmbH
 * @license     http://opensource.org/licenses/osl-3.0.php
 *              Open Software License (OSL 3.0)
 * @author      Bernhard Wick <b.wick@techdivision.com>
 */
class SkeletonFilter extends AbstractFilter
{

    /**
     * @const   int
     */
    const FILTER_ORDER = 0;

    /**
     * @var FunctionDefinitionList
     */
    public $params;

    /**
     * @return int
     */
    public function getFilterOrder()
    {
        return self::FILTER_ORDER;
    }

    /**
     * We do not have any dependencies here. So we will always return true.
     *
     * @return bool
     */
    public function dependenciesMet()
    {
        return true;
    }

    /**
     * @param $in
     * @param $out
     * @param $consumed
     * @param $closing
     * @return int|void
     * @throws \TechDivision\PBC\Exceptions\GeneratorException
     */
    public function filter($in, $out, &$consumed, $closing)
    {
        $path = $this->params[1];
        $functionDefinitions = $this->params[0];
        // Get our buckets from the stream
        $functionHook = '';
        $firstIteration = true;
        while ($bucket = stream_bucket_make_writeable($in)) {

            // Lets cave in the original filepath and the modification time
            if ($firstIteration === true) {

                $bucket->data = str_replace(
                    '<?php',
                    '<?php /* ' . PBC_ORIGINAL_PATH_HINT . $path . '#' . filemtime(
                        $path
                    ) . PBC_ORIGINAL_PATH_HINT . ' */',
                    $bucket->data
                );
                $firstIteration = false;
            }

            // Get the tokens
            $tokens = token_get_all($bucket->data);

            // Go through the tokens and check what we found
            $tokensCount = count($tokens);
            for ($i = 0; $i < $tokensCount; $i++) {

                // Has to be done only once at the beginning of the definition
                if (empty($functionHook)) {

                    // We need something to hook into, right after class header seems fine
                    if (is_array($tokens[$i]) && $tokens[$i][0] === T_CLASS) {

                        for ($j = $i; $j < $tokensCount; $j++) {

                            if (is_array($tokens[$j])) {

                                $functionHook .= $tokens[$j][1];
                            } else {

                                $functionHook .= $tokens[$j];
                            }

                            // If we got the opening bracket we can break
                            if ($tokens[$j] === '{') {

                                break;
                            }
                        }

                        // If the function hook is empty we failed and should stop what we are doing
                        if (empty($functionHook)) {

                            throw new GeneratorException();
                        }

                        // Insert the placeholder for our function hook.
                        // All following injects into the structure body will rely on it
                        $bucket->data = str_replace(
                            $functionHook,
                            $functionHook . PBC_FUNCTION_HOOK_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE,
                            $bucket->data
                        );
                        $functionHook = PBC_FUNCTION_HOOK_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE;
                    }

                    // We have to create the local constants which will substitute __DIR__ and __FILE__
                    // within the cache folder.
                    $this->injectMagicConstants($bucket->data, $path);

                }
                // Did we find a function? If so check if we know that thing and insert the code of its preconditions.
                if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {

                    // Get the name of the function
                    $functionName = $tokens[$i + 2][1];

                    // Check if we got the function in our list, if not continue
                    $functionDefinition = $functionDefinitions->get($functionName);
                    if (!$functionDefinition instanceof FunctionDefinition) {

                        continue;

                    } else {

                        // We do not have to create a proxy function for abstract functions
                        if ($functionDefinition->isAbstract === true) {

                            continue;
                        }

                        // We have to set the visibility to private to avoid 
                        // issues with missing child implementations
                        $visibilityHook = '';
                        $visibility = '';
                        for ($j = $i + 2; $j > $i - 8; $j--) {

                            // If we found the visibility
                            if (@$tokens[$j][0] === T_PUBLIC ||
                                @$tokens[$j][0] === T_PROTECTED ||
                                    @$tokens[$j][0] === T_PRIVATE
                            ) {

                                $visibility = $tokens[$j][1];
                                break;
                            }

                            // If we found something else which means there is no visibility
                            if (@$tokens[$j] === ';' ||
                                @$tokens[$j][0] === T_DOC_COMMENT ||
                                    @$tokens[$j] === '}'
                            ) {

                                break;
                            }

                            // Build up the hook for replacement
                            if (is_array($tokens[$j])) {

                                $visibilityHook = $tokens[$j][1] . $visibilityHook;

                            } else {

                                $visibilityHook = $tokens[$j] . $visibilityHook;
                            }
                        }

                        // Change the function name to indicate this is the original function.
                        // Also change the visibility to private
                        $bucket->data = preg_replace(
                            '%' . $visibility . $visibilityHook . ' *\(%',
                            'private' . $visibilityHook . PBC_ORIGINAL_FUNCTION_SUFFIX . '(',
                            $bucket->data
                        );

                        // Get the code for the assertions
                        $functionCode = $this->generateFunctionCode($functionDefinition);

                        // Insert the code
                        $bucket->data = str_replace($functionHook, $functionHook . $functionCode, $bucket->data);

                        // "Destroy" the function definition to avoid reusing it in the next loop iteration
                        $functionDefinition = null;
                    }
                }
            }

            // We have to substitute magic __DIR__ and __FILE__ constants
            $this->substituteMagicConstants($bucket->data);

            // Tell them how much we already processed, and stuff it back into the output
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    /**
     * Will substitute all magic __DIR__ and __FILE__ constants with our prepared substitutes to
     * emulate original original filesystem context when in cache folder.
     *
     * @param $bucketData
     * @return bool
     */
    private function substituteMagicConstants(& $bucketData)
    {
        // Inject the code
        $bucketData = str_replace(
            array('__DIR__', '__FILE__'),
            array('self::' . PBC_DIR_SUBSTITUTE, 'self::' . PBC_FILE_SUBSTITUTE),
            $bucketData
        );

        // Still here? Success then.
        return true;
    }

    /**
     * Will inject the code to declare our local constants PBC_FILE_SUBSTITUTE and PBC_DIR_SUBSTITUTE
     * which are used for substitution of __FILE__ and __DIR__.
     *
     * @param $bucketData
     * @param string $file
     * @return bool
     */
    private function injectMagicConstants(& $bucketData, $file)
    {
        $dir = dirname($file);
        $functionHook = PBC_FUNCTION_HOOK_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE;

        // Build up the needed code for __DIR__ substitution
        $code = '/**
     * @const   string
     */
    const ' . PBC_DIR_SUBSTITUTE . ' = "' . $dir . '";';

        // Build up the needed code for __FILE__ substitution
        $code .= '/**
     * @const   string
     */
    const ' . PBC_FILE_SUBSTITUTE . ' = "' . $file . '";';

        // Inject the code
        $bucketData = str_replace($functionHook, $functionHook . $code, $bucketData);

        // Still here? Success then.
        return true;
    }

    /**
     * @param   FunctionDefinition $functionDefinition
     * @return  string
     */
    private function generateFunctionCode(FunctionDefinition $functionDefinition)
    {

        // __get and __set need some special steps so we can inject our own logic into them
        $injectNeeded = false;
        if ($functionDefinition->name === '__get' || $functionDefinition->name === '__set') {

            $injectNeeded = true;
        }

        // Build up the header
        $code = $functionDefinition->getHeader('definition');

        // Now just place all the placeholder for other filters to come
        $code .= '{' . PBC_CONTRACT_CONTEXT . ' = \TechDivision\PBC\ContractContext::open();';

        // Invariant is not needed in private functions
        if ($functionDefinition->visibility !== 'private && !' . $functionDefinition->isStatic) {

            $code .= PBC_INVARIANT_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE;
        }

        $code .= PBC_PRECONDITION_PLACEHOLDER . $functionDefinition->name . PBC_PLACEHOLDER_CLOSE .
            PBC_OLD_SETUP_PLACEHOLDER . $functionDefinition->name . PBC_PLACEHOLDER_CLOSE;

        // If we inject something we might need a try ... catch around the original call.
        if ($injectNeeded === true) {

            $code .= 'try {';
        }

        // Build up the original function as a closure
        $code .= PBC_CLOSURE_VARIABLE . ' = ' . $functionDefinition->getHeader('closure') . '{'
            . $functionDefinition->body . '};';

        // Build up the call to the original function.
        $code .= PBC_KEYWORD_RESULT . ' = ' . PBC_CLOSURE_VARIABLE . '();';

        // Finish the try ... catch and place the inject marker
        if ($injectNeeded === true) {

            $code .= '} catch (\Exception $e) {}' . PBC_METHOD_INJECT_PLACEHOLDER . $functionDefinition->name . PBC_PLACEHOLDER_CLOSE;
        }

        // No just place all the other placeholder for other filters to come
        $code .= PBC_POSTCONDITION_PLACEHOLDER . $functionDefinition->name . PBC_PLACEHOLDER_CLOSE;

        // Invariant is not needed in private functions
        if ($functionDefinition->visibility !== 'private && !' . $functionDefinition->isStatic) {

            $code .= PBC_INVARIANT_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE;
        }

        $code .= 'if (' . PBC_CONTRACT_CONTEXT . ') {\TechDivision\PBC\ContractContext::close();}
            return ' . PBC_KEYWORD_RESULT . ';}';

        return $code;
    }

}
