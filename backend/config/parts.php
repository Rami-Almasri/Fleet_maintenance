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
    |   false — a line whose text matches no catalog part is still saved with a null
    |           link, and listed by `php artisan parts:link-required`.
    |   true  — such a line is refused at the door.
    |
    | ENABLED 2026-08-04, now that RequiredPartsEditor picks from the catalog instead
    | of accepting typed text. The picker makes compliance possible; enforcement makes
    | it certain. Without both, the free-text problem simply comes back through any
    | client that keeps posting a name.
    |
    | Enforcement applies at WRITE time only. Rows already stored with a null link are
    | untouched and still readable — history is never invalidated retroactively. What
    | changes is that re-saving a report containing an unidentifiable part now fails
    | until someone picks the part, which is the point.
    |
    | `parts:link-required` reports what is still unlinked. Two live lines match no
    | catalog part ("break", "Front brake disc") and need a human decision; neither is
    | pending against an open edit, so nothing is blocked today.
    |
    | Set PARTS_REQUIRE_CATALOG_LINK=false to roll back without a deploy if a client
    | turns out to still be posting free text.
    |
    */
    'require_catalog_link' => env('PARTS_REQUIRE_CATALOG_LINK', true),

];
