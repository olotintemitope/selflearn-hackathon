<?php

namespace App\Services\Learning;

final class TutorFallbackCopy
{
    private const COPY = [
        'en' => [
            'no_evidence' => 'I could not find enough detail in your study materials to answer that. Try asking about one idea in your study path.',
            'no_evidence_status' => 'The tutor could not find enough detail in your materials. Try asking about one idea in your study path.',
            'reply_ready' => 'A new tutor message follows.',
            'citation_fallback' => 'I could not verify a complete lesson about :concept. These passages are linked to that idea. Read them, then explain the idea in your own words. I will help you check it.',
            'citation_fallback_status' => 'The full answer could not be checked, so the source passages for this topic are shown instead.',
            'citation_unavailable' => 'I could not verify a reliable answer from your materials. Your question is saved. Choose a topic in your path or ask a narrower question.',
            'guided_note_label' => 'Source passages for your selected topic',
            'insufficient_label' => 'Not enough detail in your materials',
            'go_to_question' => 'Go to your question',
            'continue_path' => 'Continue with your learning path',
        ],
        'ha' => [
            'no_evidence' => 'Ban sami isasshen bayani a cikin kayan karatunku da zan amsa wannan tambayar ba. Ku gwada tambayar wani batu guda a cikin tsarin karatunku.',
            'no_evidence_status' => 'Mai koyarwa bai sami isasshen bayani a cikin kayan karatunku ba. Ku gwada tambayar wani batu guda a cikin hanyar karatunku.',
            'reply_ready' => 'Ga sabon saƙo daga mai koyarwa a ƙasa.',
            'citation_fallback' => 'Ban iya tabbatar da cikakken bayani game da :concept ba. An haɗa waɗannan sassan da wannan batu. Ku karanta su, sannan ku bayyana fahimtarku da kalmominku. Zan taimaka muku ku duba ta.',
            'citation_fallback_status' => 'Ba a iya tabbatar da cikakkiyar amsa ba, saboda haka an nuna sassan tushe na wannan batu.',
            'citation_unavailable' => 'Ban iya tabbatar da amsa daga kayan karatunku ba. An adana tambayarku. Ku zaɓi batu a cikin hanyar karatunku ko ku yi tambaya mafi takamaimai.',
            'guided_note_label' => 'Sassan tushe na batun da kuka zaɓa',
            'insufficient_label' => 'Bayanin kayan karatunku bai isa ba',
            'go_to_question' => 'Je zuwa tambayarku',
            'continue_path' => 'Ci gaba da hanyar karatunku',
        ],
        'yo' => [
            'no_evidence' => 'Mi ò rí ìwífún tó tó nínú àwọn ohun èlò ìkẹ́kọ̀ọ́ rẹ láti dáhùn ìbéèrè yìí. Gbìyànjú láti béèrè nípa ọ̀kan lára àwọn kókó inú ọ̀nà ìkẹ́kọ̀ọ́ rẹ.',
            'no_evidence_status' => 'Olùkọ́ kò rí ìwífún tó tó nínú àwọn ohun èlò rẹ. Gbìyànjú láti béèrè nípa ọ̀kan lára àwọn kókó inú ọ̀nà ìkẹ́kọ̀ọ́ rẹ.',
            'reply_ready' => 'Ìfiránṣẹ́ tuntun láti ọ̀dọ̀ olùkọ́ wà ní ìsàlẹ̀.',
            'citation_fallback' => 'Mi ò lè fìdí ẹ̀kọ́ tó péye nípa :concept múlẹ̀. Àwọn àyọkà wọ̀nyí ni a so mọ́ èrò yẹn. Kà wọ́n, lẹ́yìn náà ṣàlàyé èrò náà ní ọ̀rọ̀ tìrẹ. Màá ràn ọ́ lọ́wọ́ láti ṣàyẹ̀wò rẹ.',
            'citation_fallback_status' => 'A kò lè fìdí gbogbo ìdáhùn múlẹ̀, nítorí náà a fi àwọn àyọkà orísun fún kókó yìí hàn dípò rẹ̀.',
            'citation_unavailable' => 'Mi ò lè fìdí ìdáhùn tó ṣeé gbẹ́kẹ̀ lé múlẹ̀ láti inú àwọn ohun èlò rẹ. A ti fi ìbéèrè rẹ pamọ́. Yan kókó kan nínú ọ̀nà ìkẹ́kọ̀ọ́ rẹ tàbí béèrè ìbéèrè tó mọ́ sí i.',
            'guided_note_label' => 'Àwọn àyọkà orísun fún kókó tí o yàn',
            'insufficient_label' => 'Ìwífún inú àwọn ohun èlò rẹ kò tó',
            'go_to_question' => 'Lọ sí ìbéèrè rẹ',
            'continue_path' => 'Tẹ̀síwájú pẹ̀lú ọ̀nà ìkẹ́kọ̀ọ́ rẹ',
        ],
        'ig' => [
            'no_evidence' => 'Ahụghị m ozi zuru ezu n’ihe ọmụmụ gị iji zaa ajụjụ a. Gbalịa jụọ maka otu isiokwu dị n’usoro mmụta gị.',
            'no_evidence_status' => 'Onye nkuzi ahụghị ozi zuru ezu n’ihe ọmụmụ gị. Gbalịa jụọ maka otu isiokwu dị n’usoro mmụta gị.',
            'reply_ready' => 'Ozi ọhụrụ sitere n’aka onye nkuzi dị n’okpuru.',
            'citation_fallback' => 'Enweghị m ike ikwenye na nkuzi zuru oke gbasara :concept. E jikọtara akụkụ ndị a na isiokwu ahụ. Gụọ ha, kọwaa nghọta gị n’okwu nke gị. M ga-enyere gị aka ịlele ya.',
            'citation_fallback_status' => 'Enweghị ike ikwenye na azịza zuru ezu, ya mere egosiri akụkụ isi mmalite maka isiokwu a.',
            'citation_unavailable' => 'Enweghị m ike ikwenye na azịza a pụrụ ịtụkwasị obi site n’ihe ọmụmụ gị. Echekwara ajụjụ gị. Họrọ isiokwu dị n’usoro mmụta gị ma ọ bụ jụọ ajụjụ doro anya karị.',
            'guided_note_label' => 'Akụkụ isi mmalite maka isiokwu ị họọrọ',
            'insufficient_label' => 'Ozi dị n’ihe ọmụmụ gị ezughị',
            'go_to_question' => 'Gaa n’ajụjụ gị',
            'continue_path' => 'Gaa n’ihu n’usoro mmụta gị',
        ],
    ];

    public function language(?string $language): string
    {
        return isset(self::COPY[$language ?? 'en']) ? ($language ?? 'en') : 'en';
    }

    public function text(?string $language, string $key, array $replace = []): string
    {
        $resolved = $this->language($language);
        $text = self::COPY[$resolved][$key] ?? self::COPY['en'][$key] ?? '';

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }
}
