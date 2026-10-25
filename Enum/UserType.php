<?php

namespace Enum;

/** ユーザー種別。DBのuser_typeに対応する。 */
enum UserType: int
{
    case Staff = 1;
    case Cast = 2;

}
