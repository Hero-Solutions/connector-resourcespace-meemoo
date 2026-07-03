<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ListCreatorsCommand extends Command
{
    public function __construct(private ParameterBagInterface $params)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:list-creators')
            ->setDescription('Lists all distinct values of the ResourceSpace "creator" field per collection, with counts. Read-only; use this to build the digitization partner list in connector.yml.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');
        $resourceSpace = new ResourceSpace($this->params);
        $collections = $this->params->get('collections');
        $collectionKey = $collections['key'];

        $combined = [];
        foreach ($collections['values'] as $collection) {
            $allResources = $resourceSpace->getAllResources(urlencode('"' . $collectionKey . ':' . $collection . '"'));
            if (!is_array($allResources)) {
                echo 'ERROR: could not fetch resources for ' . $collection . '.' . PHP_EOL;
                continue;
            }

            $total = count($allResources);
            echo '=== ' . $collection . ' (' . $total . ' resources) ===' . PHP_EOL;

            $tally = [];
            $emptyCount = 0;
            $done = 0;
            foreach ($allResources as $resourceInfo) {
                $done++;
                if ($verbose && $done % 100 === 0) {
                    echo '  ...' . $done . '/' . $total . PHP_EOL;
                }

                $fieldData = $resourceSpace->getRawResourceFieldData($resourceInfo['ref']);
                if (!is_array($fieldData)) {
                    continue;
                }
                foreach ($fieldData as $field) {
                    if ($field['name'] !== 'creator') {
                        continue;
                    }
                    if (trim($field['value'] ?? '') === '') {
                        $emptyCount++;
                        break;
                    }
                    // Split on comma, exactly like the metadata template does
                    foreach (explode(',', $field['value']) as $value) {
                        $value = trim($value);
                        if ($value === '') {
                            continue;
                        }
                        $tally[$value] = ($tally[$value] ?? 0) + 1;
                        $combined[$value] = ($combined[$value] ?? 0) + 1;
                    }
                    break;
                }
            }

            arsort($tally);
            foreach ($tally as $value => $count) {
                echo str_pad($count, 6, ' ', STR_PAD_LEFT) . ' x ' . $value . PHP_EOL;
            }
            echo str_pad($emptyCount, 6, ' ', STR_PAD_LEFT) . ' x (leeg)' . PHP_EOL;
        }

        arsort($combined);
        echo PHP_EOL . '=== Alle collecties samen: ' . count($combined) . ' verschillende waarden ===' . PHP_EOL;
        foreach ($combined as $value => $count) {
            echo str_pad($count, 6, ' ', STR_PAD_LEFT) . ' x ' . $value . PHP_EOL;
        }

        return 0;
    }
}
