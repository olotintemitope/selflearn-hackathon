<?php

namespace App\Services\Learning;

interface ConceptExtractor
{
    /**
     * @param  array<int, array{id:int, content:string, page:int|null}>  $chunks
     * @return array{concepts:array<int, array{id:string,name:string,description:string,source_chunk_ids:array<int,int>}>,dependencies:array<int,array{concept_id:string,prerequisite_id:string,source_supported:bool}>}
     */
    public function extract(array $chunks): array;
}
