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
            ->setDescription('Lists all distinct values of the ResourceSpace "creator" field per collection, with counts. Read-only; use this to build the digitization partner list in connector.yml. Large collections: run with php -d memory_limit=3G.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resourceSpace = new ResourceSpace($this->params);
        $collections = $this->params->get('collections');
        $collectionKey = $collections['key'];

        // The creator value is included in the do_search rows as 'field<ref>';
        // the field ref is instance-specific, so resolve it once via one resource's field data.
        $creatorFieldKey = null;

        $combined = [];
        foreach ($collections['values'] as $collection) {
            echo 'Fetching all ' . $collection . ' resources (one search call, this may take a minute)...' . PHP_EOL;
            $allResources = $resourceSpace->getAllResources(urlencode('"' . $collectionKey . ':' . $collection . '"'));
            if (!is_array($allResources)) {
                echo 'ERROR: could not fetch resources for ' . $collection . '.' . PHP_EOL;
                continue;
            }

            $total = count($allResources);
            echo '=== ' . $collection . ' (' . $total . ' resources) ===' . PHP_EOL;
            if ($total === 0) {
                continue;
            }

            if ($creatorFieldKey === null) {
                $creatorFieldKey = $this->findCreatorFieldKey($resourceSpace, $allResources[0]['ref']);
                if ($creatorFieldKey === null) {
                    echo 'ERROR: could not determine the field reference of "creator" via resource ' . $allResources[0]['ref'] . '.' . PHP_EOL;
                    continue;
                }
            }

            $tally = [];
            $emptyCount = 0;
            foreach ($allResources as $row) {
                $raw = trim((string) ($row[$creatorFieldKey] ?? ''));
                if ($raw === '') {
                    $emptyCount++;
                    continue;
                }
                // Split on comma, exactly like the metadata template does
                foreach (explode(',', $raw) as $value) {
                    $value = trim($value);
                    if ($value === '') {
                        continue;
                    }
                    $tally[$value] = ($tally[$value] ?? 0) + 1;
                    $combined[$value] = ($combined[$value] ?? 0) + 1;
                }
            }
            unset($allResources);

            arsort($tally);
            foreach ($tally as $value => $count) {
                echo str_pad($count, 7, ' ', STR_PAD_LEFT) . ' x ' . $value . PHP_EOL;
            }
            echo str_pad($emptyCount, 7, ' ', STR_PAD_LEFT) . ' x (leeg)' . PHP_EOL;
        }

        arsort($combined);
        echo PHP_EOL . '=== Alle collecties samen: ' . count($combined) . ' verschillende waarden ===' . PHP_EOL;
        foreach ($combined as $value => $count) {
            echo str_pad($count, 7, ' ', STR_PAD_LEFT) . ' x ' . $value . PHP_EOL;
        }

        return 0;
    }

    private function findCreatorFieldKey(ResourceSpace $resourceSpace, $resourceRef): ?string
    {
        $fieldData = $resourceSpace->getRawResourceFieldData($resourceRef);
        if (!is_array($fieldData)) {
            return null;
        }
        foreach ($fieldData as $field) {
            if ($field['name'] === 'creator') {
                return 'field' . $field['ref'];
            }
        }
        return null;
    }
}
