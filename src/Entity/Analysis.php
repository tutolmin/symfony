<?php

// src/Entity/Analysis.php

namespace App\Entity;

class Analysis
{
    // Array of Analysis node statuses
    const STATUS = ['Pending','Processing','Partially',
        'Skipped','Evaluated','Exported','Complete'];

    // Array of valid Analysis node actions
    const ACTION = ['Creation','Promotion','StatusChange',
        'DepthChange','SideChange']; 

    // Array of valid analysis node actions
    const SIDE = ['White' => 'WhiteSide', 'Black' => 'BlackSide'];
}
?>
