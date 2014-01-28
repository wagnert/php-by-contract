<?php

/**
 * TechDivision\PBC\StreamFilters\InvariantFilter
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 */

namespace TechDivision\PBC\StreamFilters;

use TechDivision\PBC\Entities\Lists\AttributeDefinitionList;
use TechDivision\PBC\Entities\Lists\TypedListList;
use TechDivision\PBC\Exceptions\GeneratorException;
use TechDivision\PBC\Interfaces\StructureDefinition;

/**
 * @package     TechDivision\PBC
 * @subpackage  StreamFilters
 * @copyright   Copyright (c) 2013 <info@techdivision.com> - TechDivision GmbH
 * @license     http://opensource.org/licenses/osl-3.0.php
 *              Open Software License (OSL 3.0)
 * @author      Bernhard Wick <b.wick@techdivision.com>
 */
class InvariantFilter extends AbstractFilter
{

    /**
     * @const   int
     */
    const FILTER_ORDER = 3;

    /**
     * @var array
     */
    private $dependencies = array('SkeletonFilter');

    /**
     * @var StructureDefinition
     */
    public $params;

    /**
     * @return array
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    /**
     * @return int
     */
    public function getFilterOrder()
    {
        return self::FILTER_ORDER;
    }

    /**
     * @throws \Exception
     */
    public function dependenciesMet()
    {
        throw new \Exception();
    }

    /**
     * @param $in
     * @param $out
     * @param $consumed
     * @param $closing
     *
     * @return int
     * @throws GeneratorException
     */
    public function filter($in, $out, &$consumed, $closing)
    {
        $structureDefinition = $this->params;

        // After iterate over the attributes and build up our array of attributes we have to include in our
        // checking mechanism.
        $obsoleteProperties = array();
        $propertyReplacements = array();
        $iterator = $structureDefinition->attributeDefinitions->getIterator();
        for ($i = 0; $i < $iterator->count(); $i++) {

            // Get the current attribute for more easy access
            $attribute = $iterator->current();

            // Only enter the attribute if it is used in an invariant and it is not private
            if ($attribute->inInvariant && $attribute->visibility !== 'private') {

                // Build up our regex expression to filter them out
                $obsoleteProperties[] = '/' . $attribute->visibility . '.*?\\' . $attribute->name . '/';
                $propertyReplacements[] = 'private ' . $attribute->name;
            }

            // Move the iterator
            $iterator->next();
        }

        // Get our buckets from the stream
        $functionHook = '';
        while ($bucket = stream_bucket_make_writeable($in)) {

            // We only have to do that once!
            if (empty($functionHook)) {

                $functionHook = PBC_FUNCTION_HOOK_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE;

                // Get the code for our attribute storage
                $attributeCode = $this->generateAttributeCode($structureDefinition->attributeDefinitions);

                // Get the code for the assertions
                $code = $this->generateFunctionCode($structureDefinition->getInvariants());

                // Insert the code
                $bucket->data = str_replace(
                    array(
                        $functionHook,
                        $functionHook
                    ),
                    array(
                        $functionHook . $attributeCode,
                        $functionHook . $code
                    ),
                    $bucket->data
                );

                // Determine if we need the __set method to be injected
                if ($structureDefinition->functionDefinitions->entryExists('__set')) {

                    // Get the code for our __set() method
                    $setCode = $this->generateSetCode($structureDefinition->hasParents(), true);
                    $bucket->data = str_replace(
                        PBC_METHOD_INJECT_PLACEHOLDER . '__set' . PBC_PLACEHOLDER_CLOSE,
                        $setCode,
                        $bucket->data
                    );

                } else {

                    $setCode = $this->generateSetCode($structureDefinition->hasParents());
                    $bucket->data = str_replace(
                        $functionHook,
                        $functionHook . $setCode,
                        $bucket->data
                    );
                }

                // Determine if we need the __get method to be injected
                if ($structureDefinition->functionDefinitions->entryExists('__get')) {

                    // Get the code for our __set() method
                    $getCode = $this->generateGetCode($structureDefinition->hasParents(), true);
                    $bucket->data = str_replace(
                        PBC_METHOD_INJECT_PLACEHOLDER . '__get' . PBC_PLACEHOLDER_CLOSE,
                        $getCode,
                        $bucket->data
                    );

                } else {

                    $getCode = $this->generateGetCode($structureDefinition->hasParents());
                    $bucket->data = str_replace(
                        $functionHook,
                        $functionHook . $getCode,
                        $bucket->data
                    );
                }
            }

            // We need the code to call the invariant
            $this->injectInvariantCall($bucket->data);

            // Remove all the properties we will take care of with our magic setter and getter
            $bucket->data = preg_replace($obsoleteProperties, $propertyReplacements, $bucket->data, 1);

            // Tell them how much we already processed, and stuff it back into the output
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    /**
     * @param AttributeDefinitionList $attributeDefinitions
     *
     * @return string
     */
    private function generateAttributeCode(AttributeDefinitionList $attributeDefinitions)
    {
        // We should create attributes to store our attribute types
        $code = '/**
            * @var array
            */
            private $' . PBC_ATTRIBUTE_STORAGE . ' = array(';

        // After iterate over the attributes and build up our array
        $iterator = $attributeDefinitions->getIterator();
        for ($i = 0; $i < $iterator->count(); $i++) {

            // Get the current attribute for more easy access
            $attribute = $iterator->current();

            // Only enter the attribute if it is used in an invariant and it is not private
            if ($attribute->inInvariant && $attribute->visibility !== 'private') {

                $code .= '"' . substr($attribute->name, 1) . '"';
                $code .= ' => array("visibility" => "' . $attribute->visibility . '", ';

                // Now check if we need any keywords for the variable identity
                if ($attribute->isStatic) {

                    $code .= '"static" => true';
                } else {

                    $code .= '"static" => false';
                }
                $code .= '),';
            }

            // Move the iterator
            $iterator->next();
        }
        $code .= ');
        ';

        return $code;
    }

    /**
     * @param $hasParents
     *
     * @return string
     */
    private function generateSetCode($hasParents, $injected = false)
    {

        // We only need the method header if we don't inject
        if ($injected === false) {

            $code = '/**
             * Magic function to forward writing property access calls if within visibility boundaries.
             *
             * @throws InvalidArgumentException
             */
            public function __set($name, $value)
            {';
        } else {

            $code = '';
        }

        $code .= PBC_CONTRACT_CONTEXT . ' = \TechDivision\PBC\ContractContext::open();
        // Does this property even exist? If not, throw an exception
            if (!isset($this->' . PBC_ATTRIBUTE_STORAGE . '[$name])) {';

        if ($hasParents) {

            $code .= 'return parent::__set($name, $value);';
        } else {

            $code .= 'if (property_exists($this, $name)) {
\TechDivision\PBC\ContractContext::close();
                throw new \InvalidArgumentException;
            } else {
\TechDivision\PBC\ContractContext::close();
                throw new \TechDivision\PBC\Exceptions\MissingPropertyException("Property $name does not exist in " .
                    __CLASS__);
            }';
        }

        $code .= '}
        // Check if the invariant holds
            ' . PBC_INVARIANT_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE .
            '// Now check what kind of visibility we would have
            $attribute = $this->' . PBC_ATTRIBUTE_STORAGE . '[$name];
            switch ($attribute["visibility"]) {

                case "protected" :

                    if (is_subclass_of(get_called_class(), __CLASS__)) {

                        $this->$name = $value;

                    } else {

                        \TechDivision\PBC\ContractContext::close();
                        throw new \InvalidArgumentException;
                    }
                    break;

                case "public" :

                    $this->$name = $value;
                    break;

                default :

                    \TechDivision\PBC\ContractContext::close();
                    throw new \InvalidArgumentException;
                    break;
            }

            // Check if the invariant holds
            ' . PBC_INVARIANT_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE .
            '\TechDivision\PBC\ContractContext::close();';

        // We do not need the method encasing brackets if we inject
        if ($injected === false) {

            $code .= '}';
        }

        return $code;
    }

    /**
     * @param $hasParents
     *
     * @return string
     */
    private function generateGetCode($hasParents, $injected = false)
    {

        // We only need the method header if we don't inject
        if ($injected === false) {

            $code = '/**
         * Magic function to forward reading property access calls if within visibility boundaries.
         *
         * @throws InvalidArgumentException
         */
        public function __get($name)
        {';
        } else {

            $code = '';
        }
        $code .=
            '// Does this property even exist? If not, throw an exception
            if (!isset($this->' . PBC_ATTRIBUTE_STORAGE . '[$name])) {';

        if ($hasParents) {

            $code .= 'return parent::__get($name);';
        } else {

            $code .= 'if (property_exists($this, $name)) {

                throw new \InvalidArgumentException;
            } else {

                throw new \TechDivision\PBC\Exceptions\MissingPropertyException("Property $name does not exist in " .
                    __CLASS__);
            }';
        }

        $code .= '}

        // Now check what kind of visibility we would have
        $attribute = $this->' . PBC_ATTRIBUTE_STORAGE . '[$name];
        switch ($attribute["visibility"]) {

            case "protected" :

                if (is_subclass_of(get_called_class(), __CLASS__)) {

                    return $this->$name;

                } else {

                    throw new \InvalidArgumentException;
                }
                break;

            case "public" :

                return $this->$name;
                break;

            default :

                throw new \InvalidArgumentException;
                break;
        }';

        // We do not need the method encasing brackets if we inject
        if ($injected === false) {

            $code .= '}';
        }

        return $code;
    }

    /**
     * @param $bucketData
     *
     * @return bool
     */
    private function injectInvariantCall(& $bucketData)
    {
        $code = 'if (' . PBC_CONTRACT_CONTEXT . ' === true) {
            $this->' . PBC_CLASS_INVARIANT_NAME . '(__METHOD__);}';

        // Still here? Then inject the clone statement to preserve an instance of the object prior to our call.
        $bucketData = str_replace(
            PBC_INVARIANT_PLACEHOLDER . PBC_PLACEHOLDER_CLOSE,
            $code,
            $bucketData
        );

        // Still here? We encountered no error then.
        return true;
    }

    /**
     * @param TypedListList $assertionLists
     *
     * @return string
     */
    private function generateFunctionCode(TypedListList $assertionLists)
    {
        $code = 'protected function ' . PBC_CLASS_INVARIANT_NAME . '($callingMethod) {';

        $invariantIterator = $assertionLists->getIterator();
        for ($i = 0; $i < $invariantIterator->count(); $i++) {

            // Create the inner loop for the different assertions
            if ($invariantIterator->current()->count() !== 0) {

                $assertionIterator = $invariantIterator->current()->getIterator();
                $codeFragment = array();

                for ($j = 0; $j < $assertionIterator->count(); $j++) {

                    $codeFragment[] = $assertionIterator->current()->getString();

                    $assertionIterator->next();
                }
                $code .= 'if (!((' . implode(') && (', $codeFragment) . '))){' .
                    PBC_FAILURE_VARIABLE . ' = \'(' . str_replace(
                        '\'',
                        '"',
                        implode(') && (', $codeFragment)
                    ) . ')\';' .
                    PBC_PROCESSING_PLACEHOLDER . 'invariant' . PBC_PLACEHOLDER_CLOSE . '}';
            }
            // increment the outer loop
            $invariantIterator->next();
        }

        $code .= '}';

        return $code;
    }
}
