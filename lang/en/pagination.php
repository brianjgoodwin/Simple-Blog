<?php

/*
 * Overrides the framework defaults, which are '&laquo; Previous' and
 * 'Next &raquo;'. Those get double-escaped when used in aria-label
 * attributes, so screen readers announced the raw entity text. Plain
 * words need no decoration.
 */
return [

    'previous' => 'Previous',
    'next' => 'Next',

];
