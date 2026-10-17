<?php

return [

    /*
    | Most assistants one account manager may have (MANAGER_ASSISTANT_PLAN.md
    | س٣). Counts active and deactivated ones — deactivating is not a way to
    | make room; deleting the assistant is not offered, by design.
    */
    'max_assistants' => (int) env('TEAM_MAX_ASSISTANTS', 10),

    /*
    | Manager assistants are switched OFF (STAFF_TEAMS_PLAN.md §٣.٥): company
    | staff members replace them. The code and any existing assistant rows are
    | kept; set TEAM_ASSISTANTS_ENABLED=true to bring the feature back. While
    | off: /team/* is refused, assistants cannot sign in and their existing
    | sessions are refused.
    */
    'assistants_enabled' => (bool) env('TEAM_ASSISTANTS_ENABLED', false),

];
