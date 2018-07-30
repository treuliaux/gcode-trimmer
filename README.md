# gcode-trimmer
A simple PHP console app to edit a gcode file and make it start from a given layer (and then resume a 3D print after an electrical failure for example)

# Quick install
Clone repo and run:\
$ composer install\
$ chmod +x gcode-edit.php

# Usage
$ ./gcode-edit.php trim <path/to/gcode/file>
