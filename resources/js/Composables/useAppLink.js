import useAlert from './useAlert'
import useAuth from './useAuth'

const { goToLogin } = useAuth()
const { setFailedAlertData } = useAlert()
export default function useAppLink() {

    async function createLink(data) {
        return await axios.post(route(`api.links.create`), data)
        .then((res) => {
            console.log(res)
            
            return res.data.link
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return null
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return null
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
            return null
        })
    }

    async function deleteLink({linkId}) {
        return await axios.post(route(`api.links.delete`, {linkId}))
        .then((res) => {
            console.log(res)
            
            return res.data.link
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return null
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return null
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
            return null
        })
    }

    async function changeLinkStatus({linkId}) {
        return await axios.post(route(`api.links.status`, {linkId}))
        .then((res) => {
            console.log(res)
            
            return res.data.link
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return null
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return null
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
            return null
        })
    }

    async function getlinks({ page, type, addedbyId = null, addedbyType = null, forId = null, forType = null }) {
        return await axios.get(route(`api.links`, {
            page,
            type,
            addedbyId,
            addedbyType,
            forId,
            forType
        }))
        .then((res) => {
            console.log(res)
            
            return res
        })
        .catch((err) => {
            console.log(err)
            goToLogin(err)

            if (err.response?.data?.message) {
                setFailedAlertData({
                    message: err.response.data.message,
                })
                return null
            }

            if (err.alert) {
                setFailedAlertData({
                    message: err.alert,
                })
                return null
            }

            setFailedAlertData({
                message: 'Something unfortunate happened. Please try again later.',
            })
            return null
        })
    }

    return {
        createLink, getlinks, deleteLink, changeLinkStatus
    }
}