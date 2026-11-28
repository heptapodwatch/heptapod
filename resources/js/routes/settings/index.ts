import apiTokens from './api-tokens'
import notifications from './notifications'
import system from './system'
import updates from './updates'
import members from './members'
import importMethod from './import'
import alerts from './alerts'
import instance from './instance'
import email from './email'
import backups from './backups'

const settings = {
    apiTokens: Object.assign(apiTokens, apiTokens),
    notifications: Object.assign(notifications, notifications),
    system: Object.assign(system, system),
    updates: Object.assign(updates, updates),
    members: Object.assign(members, members),
    import: Object.assign(importMethod, importMethod),
    alerts: Object.assign(alerts, alerts),
    instance: Object.assign(instance, instance),
    email: Object.assign(email, email),
    backups: Object.assign(backups, backups),
}

export default settings