<?php
/**
 * Created by JetBrains PhpStorm.
 * User: wickb
 * Date: 20.06.13
 * Time: 14:46
 * To change this template use File | Settings | File Templates.
 */

namespace TechDivision\PBC\Entities\Lists;

require_once __DIR__ . "/AbstractTypedList.php";

class FunctionDefinitionList extends AbstractTypedList
{
    public function __construct()
    {
        $this->itemType = 'TechDivision\PBC\Entities\Definitions\FunctionDefinition';
    }
}