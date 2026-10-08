<?php

namespace App\Support;

/**
 * The zip that journal:export writes and journal:import reads (issue #106):
 * a journal.json with the journal's rows, and an images/ folder with its
 * chart images. Rows are kept as stored (raw column values, original ids),
 * and the import renumbers them for the journal they go into.
 */
class JournalArchive
{
    /** Bumped when the layout of journal.json changes. */
    public const FORMAT = 1;

    public const DATA_FILE = 'journal.json';

    public const IMAGES_DIR = 'images/';
}
