<?php

namespace Enum;

/** 管理者権限の区分。DBのauth_typeに対応する。 */
enum AuthType: int
{
    case Standard = 0;
    case Administrator = 1;
}
