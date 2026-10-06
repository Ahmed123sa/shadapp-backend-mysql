<?php

return [

    /*
    | Most assistants one account manager may have (MANAGER_ASSISTANT_PLAN.md
    | س٣). Counts active and deactivated ones — deactivating is not a way to
    | make room; deleting the assistant is not offered, by design.
    */
    'max_assistants' => (int) env('TEAM_MAX_ASSISTANTS', 10),

];
