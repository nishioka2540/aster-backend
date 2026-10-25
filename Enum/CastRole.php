<?php

namespace Enum;

/** キャストの役職。黒服の役職とは別に扱う。 */
enum CastRole: int
{
    case Mama = 1;
    case ChiMama = 2;
    case Other = 3;
}
