import instance from './instance'
import system from './system'
import backups from './backups'
import updates from './updates'
import members from './members'
import apiTokens from './api-tokens'
import importMethod from './import'
import alerts from './alerts'
import notifications from './notifications'

const settings = {
    instance: Object.assign(instance, instance),
    system: Object.assign(system, system),
    backups: Object.assign(backups, backups),
    updates: Object.assign(updates, updates),
    members: Object.assign(members, members),
    apiTokens: Object.assign(apiTokens, apiTokens),
    import: Object.assign(importMethod, importMethod),
    alerts: Object.assign(alerts, alerts),
    notifications: Object.assign(notifications, notifications),
}

export default settings