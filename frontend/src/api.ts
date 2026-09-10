import axios from 'axios'

export const api = axios.create({ baseURL: '/api/admin', timeout: 15000 })

api.interceptors.request.use((config) => {
  const token = sessionStorage.getItem('admin_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(undefined, (error) => {
  if (error.response?.status === 401) {
    sessionStorage.removeItem('admin_token')
    location.assign('/login')
  }
  return Promise.reject(error)
})
