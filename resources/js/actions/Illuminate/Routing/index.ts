import RedirectController from './RedirectController'
import ViewController from './ViewController'

const Routing = {
    RedirectController: Object.assign(RedirectController, RedirectController),
    ViewController: Object.assign(ViewController, ViewController),
}

export default Routing