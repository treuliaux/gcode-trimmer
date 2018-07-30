<?php
/**
 * Created by PhpStorm.
 * User: treuliaux
 * Date: 27/07/2018
 * Time: 14:50
 */

namespace Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class RestartPrintCommand extends Command
{
    private $fileName;
    private $newFileName;
    private $fileLayerCount;
    private $fileStartHeight;
    private $fileLayerHeight;
    private $startLayer;
    /** @var  SymfonyStyle */
    private $io;

    public function __construct()
    {
        parent::__construct();
    }

    public function configure()
    {
        $this
            ->setName('trim')
            ->setDescription('Trim a gcode file to make it start at a given layer.')
            ->setHelp('This command allows you to trim a gcode file to make it start at a given layer...')
            ->addArgument('gcodeFile', InputArgument::REQUIRED, 'The gcode file to trim.');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->trimGcode($input, $output);
    }

    private function trimGcode(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->io->title('Gcode Trimmer Console App');
        $this->io->note(
            [
                'Please note that only Cura-generated Gcode files are supported by this tool.',
                'While it could possibly work fine with any gcode file, it has only been tested on Cura-generated files.',
            ]
        );

        $this->fileName = $input->getArgument('gcodeFile');
        $this->newFileName = str_replace('.gcode', '_EDITED.gcode', $this->fileName);

        $this->io->section('Analyzing Gcode file');
        try {
            $this->analyzeFile();
        } catch (\Exception $e) {
            $this->io->error($e->getMessage());

            return 1;
        }
        $this->io->comment(
            [
                "File name: {$this->fileName}",
                "Layers' height: {$this->fileLayerHeight}mm",
                "First layer's height: {$this->fileStartHeight}mm",
                "Total layer count: {$this->fileLayerCount}",
            ]
        );

        $this->io->section('Parameters selection');
        $this->askUser();
        $this->io->newLine();

        $this->io->section('Parameters validation');
        if (!$this->validateChoices()) {
            $this->io->newLine();
            $this->io->note('Aborted.');

            return 2;
        }
        $this->io->newLine();

        $this->io->section('Generating edited Gcode file');
        $this->generateNewGcode();
        $this->io->newLine(2);

        $this->io->success('Edited Gcode file successfully generated!');

        return 0;
    }

    private function validateChoices()
    {
        $this->io->text('You are about to create a new Gcode file with the following parameters:');
        $this->io->newLine();
        $this->io->listing(
            [
                "Source file name: {$this->fileName}

",
                "Source file layers count: {$this->fileLayerCount}",
                '',
                "New layer start number: {$this->startLayer}",
                "Layers' height: {$this->fileLayerHeight}mm",
                "First layer's height: {$this->fileStartHeight}mm",
                '',
                "New file name: {$this->newFileName}",
                'New file layers count: '.($this->fileLayerCount - $this->startLayer),
            ]
        );

        return $this->io->confirm('Are you sure ?');
    }

    private function generateNewGcode()
    {
        $this->io->progressStart($this->fileLayerCount - $this->startLayer);

        $handleRead = fopen($this->fileName, "r");
        if (!$handleRead) {
            throw new InvalidArgumentException("Cannot open file '{$this->fileName}'.");
        }
        $handleWrite = fopen($this->newFileName, 'w');
        if (!$handleWrite) {
            throw new InvalidArgumentException(
                "Cannot open file {$this->newFileName}."
            );
        }

        $addLineFlag = true;
        $layerCpt = 0;
        while (($line = fgets($handleRead)) !== false) {
            if (preg_match('/;LAYER_COUNT:\d+/', $line)) {
                $line = preg_replace(
                    '/;LAYER_COUNT:\d+/',
                    ';LAYER_COUNT:'.($this->fileLayerCount - $this->startLayer),
                    $line
                );
            }
            if (preg_match('/;LAYER:0/', $line)) {
                $addLineFlag = false;
            }
            if (preg_match("/;LAYER:{$this->startLayer}/", $line)) {
                $addLineFlag = true;
            }
            if ($addLineFlag && preg_match("/;LAYER:\d+/", $line)) {
                $line = preg_replace('/\d+/', $layerCpt++, $line);
                $this->io->progressAdvance();
            }
            if ($addLineFlag && preg_match('/Z\d+\.\d+/', $line)) {
                $line = preg_replace('/Z\d+\.\d+/', "Z{$this->fileStartHeight}", $line);
                $this->fileStartHeight += $this->fileLayerHeight;
            }
            if ($addLineFlag) {
                fwrite($handleWrite, $line);
            }
        }
        $this->io->progressFinish();
    }

    private function analyzeFile()
    {
        $handle = fopen($this->fileName, "r");
        if (!$handle) {
            throw new InvalidArgumentException("Cannot open file '{$this->fileName}'.");
        }

        while (($line = fgets($handle)) !== false) {
            $results = [];
            if (!isset($this->fileLayerCount) && preg_match('/;LAYER_COUNT:(\d+)/', $line, $results)) {
                $this->fileLayerCount = $results[1];
            }
            if (isset($this->fileStartHeight) && preg_match('/G0.*Z(\d+\.\d+)/', $line, $results)) {
                $this->fileLayerHeight = number_format(floatval($results[1]) - $this->fileStartHeight, 2);
            }
            if (!isset($this->fileStartHeight) && preg_match('/G0.*Z(\d+\.\d+)/', $line, $results)) {
                $this->fileStartHeight = floatval($results[1]);
            }
            if (isset($this->fileLayerHeight)) {
                break;
            }
        }
        fclose($handle);

        if (!isset($this->fileLayerCount) || !isset($this->fileLayerHeight) || !isset($this->fileStartHeight)) {
            throw new RuntimeException('Gcode file is invalid.');
        }
    }

    private function askUser()
    {
        $question = new Question("Select the new starting layer number (0 - {$this->fileLayerCount})");
        $question->setValidator(
            function ($answer) {
                if (!(intval($answer) >= 0 && intval($answer) <= $this->fileLayerCount)) {
                    throw new \RuntimeException("Starting layer should be between 0 and {$this->fileLayerCount}");
                }

                return intval($answer);
            }
        );
        $this->startLayer = $this->io->askQuestion($question);

        $this->io->note(
            [
                'Following values were calculated from the source file.',
                'Edit them only if you know what you are doing!',
                'If you\'re not sure, just press <enter>',
            ]
        );

        $question = new Question('Select layers\' height (in mm)', $this->fileLayerHeight);
        $question->setValidator(
            function ($answer) {
                if (!is_float(floatval($answer))) {
                    throw new \RuntimeException('Layers\' height should be a float number (in mm)');
                }

                return floatval($answer);
            }
        );
        $this->fileLayerHeight = $this->io->askQuestion($question);

        $question = new Question('Enter first layer height (in mm)', $this->fileStartHeight);
        $question->setValidator(
            function ($answer) {
                if (!is_float(floatval($answer))) {
                    throw new \RuntimeException('Height of the first layer should be a float number (in mm)');
                }

                return floatval($answer);
            }
        );
        $this->fileStartHeight = $this->io->askQuestion($question);
    }
}
