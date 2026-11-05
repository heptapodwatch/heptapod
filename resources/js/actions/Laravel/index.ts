import Fortify from './Fortify'
import Passkeys from './Passkeys'
import Horizon from './Horizon'
import Sanctum from './Sanctum'

const Laravel = {
    Fortify: Object.assign(Fortify, Fortify),
    Passkeys: Object.assign(Passkeys, Passkeys),
    Horizon: Object.assign(Horizon, Horizon),
    Sanctum: Object.assign(Sanctum, Sanctum),
}

export default Laravel