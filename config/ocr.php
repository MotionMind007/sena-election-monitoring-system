<?php

return [
    'binary' => env('TESSERACT_BINARY', 'tesseract'),
    'language' => env('TESSERACT_LANGUAGE', 'eng'),
    'page_segmentation_mode' => (int) env('TESSERACT_PSM', 6),
    'timeout' => (int) env('TESSERACT_TIMEOUT', 60),
];
