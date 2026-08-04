<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Require every required-part line to reference the catalog
    |---------------------------------------------------------------------------
    |
    | The end state is that a required part names a catalog PART, not a string.
    | Turning that on before the picker exists in the UI would strand inspectors
    | mid-report: they type a name, nothing enforces a list, and the submit would
    | simply fail. So the cutover is two-phase.
    |
    |   false (today) — a line whose text matches no catalog part is still saved,
    |                   with a null link, and listed by `php artisan parts:link-required`.
    |   true  (after the picker ships) — such a line is refused at the door.
    |
    | Flip it with PARTS_REQUIRE_CATALOG_LINK=true once the required-parts editor
    | selects from the catalog. Check `parts:link-required` reports zero unlinked
    | pending lines first, or existing work will start failing to save.
    |
    */
    'require_catalog_link' => env('PARTS_REQUIRE_CATALOG_LINK', false),

];
